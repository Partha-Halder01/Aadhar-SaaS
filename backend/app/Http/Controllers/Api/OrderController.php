<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    protected RazorpayService $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    /**
     * List user orders with optional category/status filter
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ServiceOrder::with('service')
            ->where('user_id', $user->id);

        if ($request->has('category') && !empty($request->category)) {
            $cat = $request->category;
            $query->whereHas('service', function ($q) use ($cat) {
                if ($cat === 'print' || $cat === 'print_doc') {
                    $q->whereNotIn('category', ['pan', 'pan_find']);
                } elseif ($cat === 'pan' || $cat === 'pan_find') {
                    $q->whereIn('category', ['pan', 'pan_find']);
                } else {
                    $q->where('category', $cat);
                }
            });
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('order_status', $request->status);
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 100);
        $orders = $query->orderBy('id', 'desc')->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'total' => $orders->total(),
            'current_page' => $orders->currentPage(),
        ]);
    }

    /**
     * Create new service order (Wallet or Direct UPI)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'service_id' => 'required|exists:services,id',
            'payment_method' => 'required|in:wallet,direct_upi,razorpay',
            'utr_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6,30}$/'],
            'payment_proof' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ]);

        $service = Service::where('is_active', true)->findOrFail($request->service_id);
        $amount = $service->price;

        // Process input_data JSON & sanitize to prevent path injection / IDOR
        $rawInput = json_decode($request->input('input_data', '{}'), true) ?: [];
        $inputData = [];
        foreach ($rawInput as $k => $v) {
            // Reject any file path strings supplied via raw JSON
            if (is_string($v) && (str_contains($v, '/') || str_contains($v, '\\') || str_starts_with($v, 'orders/') || str_starts_with($v, 'deliveries/') || str_starts_with($v, 'payments/'))) {
                continue;
            }
            $inputData[strip_tags(trim($k))] = is_string($v) ? strip_tags(trim($v)) : $v;
        }

        // Validate all dynamic uploaded files (e.g. file_aadhaar_file)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $allowedExtensions = ['jpeg', 'jpg', 'png', 'pdf'];
        $maxSizeBytes = 10 * 1024 * 1024; // 10MB

        foreach ($request->allFiles() as $key => $file) {
            if (str_starts_with($key, 'file_')) {
                if (!$file->isValid()) {
                    throw ValidationException::withMessages([
                        $key => ["File upload error on field [{$key}]."]
                    ]);
                }

                $ext = strtolower($file->getClientOriginalExtension());
                $mime = $file->getMimeType();

                if (!in_array($ext, $allowedExtensions) || !in_array($mime, $allowedMimes)) {
                    throw ValidationException::withMessages([
                        $key => ["Invalid file type on [{$key}]. Only PDF, JPG, and PNG citizen documents are allowed."]
                    ]);
                }

                if ($file->getSize() > $maxSizeBytes) {
                    throw ValidationException::withMessages([
                        $key => ["Uploaded file [{$key}] exceeds the maximum allowed size of 10MB."]
                    ]);
                }

                $fieldName = substr($key, 5);
                $path = $file->store('orders/customer_inputs', 'local');
                $inputData[$fieldName] = $path;
            }
        }

        $orderNumber = 'UTK-' . date('Y') . '-' . strtoupper(Str::random(6));

        // Case 1: Payment via Wallet
        if ($request->payment_method === 'wallet') {
            return DB::transaction(function () use ($user, $service, $amount, $inputData, $orderNumber) {
                // Pessimistic lock on user balance to prevent race-condition double spending
                $lockedUser = User::lockForUpdate()->findOrFail($user->id);

                if ($lockedUser->wallet_balance < $amount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient wallet balance. Please recharge your wallet or choose Direct UPI payment.'
                    ], 422);
                }

                // Deduct wallet
                $lockedUser->wallet_balance -= $amount;
                $lockedUser->save();

                // Create Order
                $order = ServiceOrder::create([
                    'order_number' => $orderNumber,
                    'user_id' => $lockedUser->id,
                    'service_id' => $service->id,
                    'input_data' => $inputData,
                    'amount' => $amount,
                    'payment_method' => 'wallet',
                    'payment_status' => 'approved',
                    'order_status' => 'processing',
                ]);

                // Create Wallet Debit Ledger
                WalletTransaction::create([
                    'user_id' => $lockedUser->id,
                    'type' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $lockedUser->wallet_balance,
                    'description' => "Payment for Order #{$orderNumber} ({$service->name})",
                    'status' => 'approved',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Order placed successfully! Amount deducted from wallet.',
                    'data' => $order->load('service'),
                    'wallet_balance' => (float) $lockedUser->wallet_balance,
                ], 201);
            });
        }

        // Case 2: Payment via Razorpay
        if ($request->payment_method === 'razorpay') {
            if (!$this->razorpayService->isConfigured()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Online payment gateway is not configured yet. Please choose Wallet or Direct UPI.'
                ], 503);
            }

            $order = ServiceOrder::create([
                'order_number' => $orderNumber,
                'user_id' => $user->id,
                'service_id' => $service->id,
                'input_data' => $inputData,
                'amount' => $amount,
                'payment_method' => 'razorpay',
                'payment_status' => 'pending',
                'order_status' => 'pending',
            ]);

            try {
                $rzpOrder = $this->razorpayService->createOrder(
                    $amount,
                    $orderNumber,
                    [
                        'service_order_id' => (string) $order->id,
                        'order_number' => $orderNumber,
                        'type' => 'service_order',
                    ]
                );

                $order->update(['razorpay_order_id' => $rzpOrder['id']]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Order initiated! Please complete payment.',
                    'requires_payment' => true,
                    'razorpay' => [
                        'key_id' => $this->razorpayService->getKeyId(),
                        'order_id' => $rzpOrder['id'],
                        'amount' => $rzpOrder['amount'], // in paise
                        'currency' => $rzpOrder['currency'],
                        'name' => 'Utkal Print Portal',
                        'description' => "Order #{$orderNumber} ({$service->name})",
                        'prefill' => [
                            'name' => $user->name,
                            'email' => $user->email,
                            'contact' => $user->phone ?? '',
                        ],
                    ],
                    'data' => $order->load('service'),
                ], 201);
            } catch (\Exception $e) {
                $order->delete();
                Log::error('Razorpay service order creation failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to initialize online payment. Please try again.'
                ], 500);
            }
        }

        // Case 3: Payment via Direct UPI
        if ($request->filled('utr_number')) {
            $cleanUtr = trim($request->utr_number);
            $duplicateOrder = ServiceOrder::where('utr_number', $cleanUtr)
                ->where('payment_status', '!=', 'rejected')
                ->exists();
            $duplicateTx = WalletTransaction::where('utr_number', $cleanUtr)
                ->where('status', '!=', 'rejected')
                ->exists();

            if ($duplicateOrder || $duplicateTx) {
                throw ValidationException::withMessages([
                    'utr_number' => ['This UTR / Transaction Reference number has already been submitted or processed.']
                ]);
            }
        }

        $proofPath = null;
        if ($request->hasFile('payment_proof')) {
            $proofPath = $request->file('payment_proof')->store('payments/orders', 'local');
        }

        $order = ServiceOrder::create([
            'order_number' => $orderNumber,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'input_data' => $inputData,
            'amount' => $amount,
            'payment_method' => 'direct_upi',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'utr_number' => $request->utr_number ? trim($request->utr_number) : null,
            'payment_proof_image' => $proofPath,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order placed successfully! Admin will verify payment and process the request.',
            'data' => $order->load('service'),
        ], 201);
    }

    /**
     * Show single order detail
     */
    public function show(Request $request, $id)
    {
        $order = ServiceOrder::with('service')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    /**
     * Submit missing/requested document in response to admin alert
     */
    public function submitDocument(Request $request, $id)
    {
        $user = $request->user();
        $order = ServiceOrder::with('service')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if ($order->doc_request_status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'There is no pending document request for this order.',
            ], 422);
        }

        $request->validate([
            'document_file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:10240',
            'notes' => 'nullable|string|max:500',
        ]);

        $path = $request->file('document_file')->store('orders/user_replies', 'local');

        // Add to input_data so it is preserved alongside all initial documents
        $inputData = $order->input_data ?: [];
        $slugTitle = \Illuminate\Support\Str::slug($order->doc_request_title ?: 'additional_doc', '_');
        $docKey = 'additional_doc_' . $slugTitle;
        if (isset($inputData[$docKey])) {
            $docKey .= '_' . time();
        }
        $inputData[$docKey] = $path;

        $order->update([
            'input_data' => $inputData,
            'doc_response_file' => $path,
            'doc_response_notes' => $request->notes ? strip_tags(trim($request->notes)) : null,
            'doc_response_submitted_at' => now(),
            'doc_request_status' => 'submitted',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Missing document uploaded successfully! Admin has been notified to continue processing your application.',
            'data' => $order->fresh('service'),
        ]);
    }

    /**
     * Authenticated, secure document download endpoint for citizen privacy
     */
    public function download(Request $request, $id, $type = 'delivery')
    {
        $order = ServiceOrder::findOrFail($id);
        if (!$this->canAccessOrder($request->user(), $order)) {
            return $this->unauthorizedDocument();
        }

        return $this->serveOrderFile($request, $order, $type);
    }

    /**
     * Issue a 5-minute signed link so documents open in a new tab without the login token in the URL
     */
    public function downloadLink(Request $request, $id, $type = 'delivery')
    {
        $order = ServiceOrder::findOrFail($id);
        if (!$this->canAccessOrder($request->user(), $order)) {
            return $this->unauthorizedDocument();
        }

        $params = ['id' => $order->id, 'type' => $type];
        if ($request->filled('file')) {
            $params['file'] = (string) $request->query('file');
        }
        if ($request->boolean('inline')) {
            $params['inline'] = 1;
        }

        return response()->json([
            'status' => 'success',
            'url' => URL::temporarySignedRoute('files.order', now()->addMinutes(5), $params, false),
        ]);
    }

    /**
     * Serve a document from a signed link (the signature proves an authorized user requested it)
     */
    public function signedDownload(Request $request, $id, $type = 'delivery')
    {
        return $this->serveOrderFile($request, ServiceOrder::findOrFail($id), $type);
    }

    protected function canAccessOrder(User $user, ServiceOrder $order): bool
    {
        return $order->user_id === $user->id || $user->role === 'admin';
    }

    protected function unauthorizedDocument()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized access to this document.'
        ], 403);
    }

    protected function serveOrderFile(Request $request, ServiceOrder $order, $type)
    {
        $filePath = null;
        $fileName = null;

        if ($request->filled('file')) {
            $reqFile = $request->query('file');
            $allowedFiles = array_filter([
                $order->delivery_file,
                $order->payment_proof_image,
                $order->doc_response_file,
            ]);
            if (is_array($order->input_data)) {
                foreach ($order->input_data as $val) {
                    if (is_string($val)) {
                        $allowedFiles[] = $val;
                    }
                }
            }
            if (in_array($reqFile, $allowedFiles, true)) {
                $filePath = $reqFile;
                $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'pdf';
                $fileName = "{$order->order_number}_attachment.{$ext}";
            }
        } elseif ($type === 'delivery') {
            $filePath = $order->delivery_file;
            $ext = pathinfo($filePath ?: '', PATHINFO_EXTENSION) ?: 'pdf';
            $fileName = "{$order->order_number}_delivered.{$ext}";
        } elseif ($type === 'payment') {
            $filePath = $order->payment_proof_image;
            $ext = pathinfo($filePath ?: '', PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = "{$order->order_number}_payment_proof.{$ext}";
        } elseif ($type === 'doc_response') {
            $filePath = $order->doc_response_file;
            $ext = pathinfo($filePath ?: '', PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = "{$order->order_number}_doc_response.{$ext}";
        } elseif (str_starts_with($type, 'input_')) {
            $key = substr($type, 6);
            $inputData = $order->input_data ?: [];
            if (isset($inputData[$key])) {
                $filePath = $inputData[$key];
                $ext = pathinfo($filePath ?: '', PATHINFO_EXTENSION) ?: 'pdf';
                $fileName = "{$order->order_number}_{$key}.{$ext}";
            }
        }

        if (!$filePath) {
            return response()->json([
                'status' => 'error',
                'message' => 'Requested file was not found or has not been uploaded yet.'
            ], 404);
        }

        $disk = 'local';
        if (!Storage::disk('local')->exists($filePath)) {
            if (Storage::disk('public')->exists($filePath)) {
                $disk = 'public';
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Requested file was not found or has not been uploaded yet.'
                ], 404);
            }
        }

        if ($request->boolean('inline')) {
            return Storage::disk($disk)->response($filePath, $fileName);
        }

        return Storage::disk($disk)->download($filePath, $fileName);
    }
}

