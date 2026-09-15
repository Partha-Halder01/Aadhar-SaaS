<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
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
     * Create new service order, paid from the user's wallet balance
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Services are paid only from the prepaid wallet; money enters the wallet via Razorpay top-ups
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'payment_method' => 'sometimes|in:wallet',
        ], [
            'payment_method.in' => 'Services can only be paid from your wallet balance. Please add money to your wallet first.',
        ]);

        $service = Service::where('is_active', true)->findOrFail($request->service_id);
        $amount = $service->price;

        // Process input_data JSON & sanitize to prevent path injection / IDOR
        $rawInput = json_decode($request->input('input_data', '{}'), true) ?: [];
        $inputData = [];
        foreach ($rawInput as $k => $v) {
            // Reject anything that looks like a storage file path (those values become downloadable attachments),
            // while still allowing normal answers such as dates "15/09/2026" or addresses "Plot 12/B"
            if (is_string($v) && (str_contains($v, '\\') || str_contains($v, '..') || preg_match('#^(orders|deliveries|payments|services|settings)/#i', $v) || preg_match('#^[\w\-]+(/[\w\-.]+)+\.[A-Za-z0-9]{2,5}$#', trim($v)))) {
                continue;
            }
            $inputData[strip_tags(trim($k))] = is_string($v) ? strip_tags(trim($v)) : $v;
        }

        // Validate all dynamic uploaded files (e.g. file_aadhaar_file) before anything is stored
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $allowedExtensions = ['jpeg', 'jpg', 'png', 'pdf'];
        $maxSizeBytes = 10 * 1024 * 1024; // 10MB

        $uploads = array_filter($request->allFiles(), fn ($key) => str_starts_with($key, 'file_'), ARRAY_FILTER_USE_KEY);

        foreach ($uploads as $key => $file) {
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
        }

        $this->validateServiceInputs($request, $service, $inputData);

        foreach ($uploads as $key => $file) {
            $inputData[substr($key, 5)] = $file->store('orders/customer_inputs', 'local');
        }

        $orderNumber = 'UTK-' . date('Y') . '-' . strtoupper(Str::random(6));

        return DB::transaction(function () use ($user, $service, $amount, $inputData, $orderNumber) {
            // Pessimistic lock on user balance to prevent race-condition double spending
            $lockedUser = User::lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->wallet_balance < $amount) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'insufficient_balance',
                    'message' => 'Insufficient wallet balance. Please add money to your wallet to place this order.',
                    'required_amount' => (float) $amount,
                    'wallet_balance' => (float) $lockedUser->wallet_balance,
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

    /**
     * Enforce the admin-defined input fields of a service (required answers, dropdown choices, required uploads)
     */
    protected function validateServiceInputs(Request $request, Service $service, array $inputData): void
    {
        $errors = [];

        foreach ((array) $service->required_fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;
            if (!$name) {
                continue;
            }

            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';

            if ($type === 'file') {
                if (!empty($field['required']) && !$request->hasFile("file_{$name}")) {
                    $errors[$name] = ["{$label} is required."];
                }
                continue;
            }

            $value = $inputData[$name] ?? null;
            $isEmpty = $value === null || (is_string($value) && trim($value) === '');

            if ($isEmpty) {
                if (!empty($field['required'])) {
                    $errors[$name] = ["{$label} is required."];
                }
                continue;
            }

            if ($type === 'select' && !in_array($value, (array) ($field['options'] ?? []), true)) {
                $errors[$name] = ["Please choose a valid option for {$label}."];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
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

