<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class LandingPageService
{
    const CACHE_KEY = 'landing_page_config';

    /**
     * Get the active landing page configuration
     */
    public function getConfig(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $raw = Setting::get('landing_page_content');
            $config = $this->getDefaultConfig();
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $config = $this->mergeConfig($config, $decoded);
                }
            }
            return $this->enrichWithLiveServices($config);
        });
    }

    /**
     * Enrich landing page services items with live database Service model data
     * (icon_type, icon_image, icon_image_url, icon_bg, icon_color, icon)
     */
    public function enrichWithLiveServices(array $config): array
    {
        try {
            if (!isset($config['services']['items']) || !is_array($config['services']['items'])) {
                return $config;
            }

            $dbServices = \App\Models\Service::where('is_active', true)->get();
            if ($dbServices->isEmpty()) {
                return $config;
            }

            foreach ($config['services']['items'] as &$item) {
                $itemId = strtolower($item['id'] ?? '');
                $itemTitle = strtolower($item['title'] ?? '');

                // Find matching service from catalog with prioritized specificity
                $matched = $dbServices->first(function ($s) use ($itemId, $itemTitle) {
                    $sSlug = strtolower($s->slug ?? '');
                    $sName = strtolower($s->name ?? '');

                    if ($itemId === 'pan_find' || str_contains($itemId, 'pan') || str_contains($itemTitle, 'lost pan') || str_contains($itemTitle, 'pan recovery')) {
                        return str_contains($sSlug, 'pan') && (str_contains($sSlug, 'find') || $s->category === 'pan_find');
                    }
                    if ($itemId === 'aadhaar_pvc' || (str_contains($itemTitle, 'aadhaar') && !str_contains($itemTitle, 'pan'))) {
                        return str_contains($sSlug, 'aadhaar') && !str_contains($sSlug, 'pan');
                    }
                    if ($itemId === 'voter_id' || str_contains($itemId, 'voter') || str_contains($itemTitle, 'voter')) {
                        return str_contains($sSlug, 'voter') || str_contains($sName, 'voter');
                    }
                    if ($itemId === 'ayushman' || str_contains($itemId, 'ayushman') || str_contains($itemTitle, 'ayushman')) {
                        return str_contains($sSlug, 'ayushman') || str_contains($sName, 'ayushman');
                    }
                    return false;
                });

                if ($matched) {
                    $item['icon_type'] = $matched->icon_type ?? ($matched->icon_image ? 'image' : 'icon');
                    if ($matched->icon) {
                        $item['icon'] = $matched->icon;
                    }
                    if ($matched->icon_image) {
                        $item['icon_image'] = $matched->icon_image;
                        $item['icon_image_url'] = $matched->icon_image_url;
                    }
                    if ($matched->icon_bg) {
                        $item['icon_bg'] = $matched->icon_bg;
                    }
                    if ($matched->icon_color) {
                        $item['icon_color'] = $matched->icon_color;
                    }
                }
            }
            unset($item);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Error enriching landing page services: ' . $e->getMessage());
        }

        // Ensure policy links point to dedicated pages if currently empty or '#'
        if (isset($config['footer'])) {
            if (empty($config['footer']['privacy_url']) || $config['footer']['privacy_url'] === '#') {
                $config['footer']['privacy_url'] = 'privacy.html';
            }
            if (empty($config['footer']['terms_url']) || $config['footer']['terms_url'] === '#') {
                $config['footer']['terms_url'] = 'terms.html';
            }
            if (empty($config['footer']['refund_url']) || $config['footer']['refund_url'] === '#') {
                $config['footer']['refund_url'] = 'refund.html';
            }
        }

        // Enrich testimonials with live customer reviews from Review model
        try {
            $liveReviews = \App\Models\Review::where('is_approved', true)
                ->latest()
                ->take(15)
                ->get();

            if ($liveReviews->isNotEmpty()) {
                $reviewItems = [];
                foreach ($liveReviews as $rev) {
                    $reviewItems[] = [
                        'id' => $rev->id,
                        'quote' => $rev->comment,
                        'author' => $rev->author_name,
                        'role' => $rev->role_or_business ?: 'CSC & Cyber Cafe Operator',
                        'initials' => $rev->initials,
                        'stars' => (int) $rev->rating,
                        'created_at' => $rev->created_at->diffForHumans(),
                    ];
                }
                $config['testimonials']['items'] = $reviewItems;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Error enriching live testimonials: ' . $e->getMessage());
        }

        return $config;
    }

    /**
     * Save the updated landing page configuration
     */
    public function saveConfig(array $data): array
    {
        $merged = $this->mergeConfig($this->getDefaultConfig(), $data);
        Setting::set('landing_page_content', json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Cache::forget(self::CACHE_KEY);
        return $merged;
    }

    /**
     * Reset landing page configuration to defaults
     */
    public function resetConfig(): array
    {
        $defaults = $this->getDefaultConfig();
        Setting::set('landing_page_content', json_encode($defaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Cache::forget(self::CACHE_KEY);
        return $defaults;
    }

    /**
     * Merge incoming config into defaults safely.
     * Associative arrays (dictionaries) are merged recursively so missing keys get defaults.
     * Sequential/Indexed arrays (lists of items/cards/steps) in incoming completely replace defaults!
     */
    public function mergeConfig(array $default, array $incoming): array
    {
        $result = $default;
        foreach ($incoming as $key => $value) {
            if (is_array($value)) {
                if ($this->isAssoc($value) && isset($result[$key]) && is_array($result[$key]) && $this->isAssoc($result[$key])) {
                    $result[$key] = $this->mergeConfig($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Check if an array is associative (key-value dictionary) or sequential list
     */
    private function isAssoc(array $arr): bool
    {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * System default landing page content
     */
    public function getDefaultConfig(): array
    {
        return [
            'visibility' => [
                'announcement' => true,
                'hero' => true,
                'showcase' => true,
                'services' => true,
                'pricing' => true,
                'workflow' => true,
                'testimonials' => true,
                'faq' => true,
                'cta' => true,
                'footer' => true,
            ],
            'announcement' => [
                'enabled' => true,
                'badge' => 'LIVE 2.0',
                'text' => 'Instant Aadhaar HD PVC Formatting & UPI Fast Approvals under 2 mins!',
                'helpline_text' => 'Helpline: +91 97358 00298',
                'helpline_link' => 'tel:+919735800298',
                'safe_text' => 'Secure Document Handling',
            ],
            'hero' => [
                'badge_icon' => 'fa-bolt',
                'badge_tag' => 'FAST & AUTOMATED',
                'badge_text' => 'All-in-One PVC Print & Document Portal for CSC & Cyber Cafes',
                'title_rest' => 'Automated',
                'title_highlight' => 'PVC Card Printing',
                'title_suffix' => '& Instant PAN Portal',
                'subtitle' => 'Quickly convert Aadhaar, Voter ID, and Ayushman PDFs into 300 DPI print-ready PVC cards in seconds. Instantly find lost PAN numbers and recharge your wallet via auto UPI QR — built specifically for Cyber Cafes, CSCs, and Retailers.',
                'cta_primary_text' => 'Get Started Free',
                'cta_primary_link' => 'register.html',
                'cta_secondary_text' => 'User Login',
                'cta_secondary_link' => 'login.html',
                'trust_items' => [],
            ],
            'showcase' => [
                'chip1_title' => 'PAN Extracted',
                'chip1_sub' => 'Matched in 0.8s',
                'chip2_title' => 'Wallet Auto-Credited',
                'chip2_sub' => '+₹500.00 via UPI QR',
                'card_title' => 'Smart PVC ID Engine',
                'applicant_name' => 'RAJESH KUMAR PATRA',
                'applicant_meta' => 'DOB: 15/08/1994 | Male',
                'applicant_state' => 'State: Odisha, India',
                'applicant_aadhaar' => 'XXXX XXXX 8924',
                'footer_text' => 'Auto CR80 Dimension Ratio',
                'cta_text' => 'Try It Live',
                'cta_link' => 'register.html',
            ],
            'services' => [
                'tag' => 'Enterprise Suite',
                'title' => 'Everything Your Cyber Cafe Needs in One Place',
                'desc' => 'Engineered for Digital Retailers, CSC Centers, and Cyber Cafes to fulfill citizen requests with lightning turnaround, highest resolution, and maximum profit margins.',
                'items' => [
                    [
                        'id' => 'aadhaar_pvc',
                        'category' => 'print',
                        'icon' => 'fa-id-card',
                        'color' => 'orange',
                        'title' => 'Aadhaar HD Smart PVC Print',
                        'badge' => 'Starting ₹20 / card',
                        'price' => '₹20',
                        'desc' => 'Upload password-protected e-Aadhaar PDFs or scans. Our engine automatically crops, straightens, sharpens photographs, and aligns dual-sided CR-80 standard PVC printable formats in crisp 300 DPI vector clarity.',
                        'features' => [
                            'Standard CR-80 PVC Dimensions (85.60 × 53.98 mm)',
                            'Auto photo contrast correction & QR code enhancement',
                            'High-resolution 300 DPI PDF output ready for direct tray printing'
                        ],
                        'action_text' => 'Start Aadhaar Print',
                        'action_link' => 'register.html'
                    ],
                    [
                        'id' => 'pan_find',
                        'category' => 'pan',
                        'icon' => 'fa-magnifying-glass-location',
                        'color' => 'green',
                        'title' => 'Lost PAN Recovery by Aadhaar',
                        'badge' => 'Starting ₹30 / search',
                        'price' => '₹30',
                        'desc' => 'Client lost their PAN card? Enter Aadhaar number and applicant details to recover verified PAN numbers in seconds.',
                        'features' => [
                            'PAN details checked by our team',
                            'Operative & Active status confirmation'
                        ],
                        'action_text' => 'Find Lost PAN',
                        'action_link' => 'register.html'
                    ],
                    [
                        'id' => 'voter_id',
                        'category' => 'print',
                        'icon' => 'fa-person-booth',
                        'color' => 'blue',
                        'title' => 'Voter ID (e-EPIC) HD Print',
                        'badge' => 'Starting ₹20 / card',
                        'price' => '₹20',
                        'desc' => 'Convert modern digital e-EPIC documents into clear laminated PVC printable sheets with official Election Commission formatting.',
                        'features' => [
                            'Dual-sided auto separation',
                            'Clean vector export'
                        ],
                        'action_text' => 'Order Voter ID',
                        'action_link' => 'register.html'
                    ],
                    [
                        'id' => 'ayushman',
                        'category' => 'print',
                        'icon' => 'fa-heart-pulse',
                        'color' => 'purple',
                        'title' => 'Ayushman PM-JAY Golden Card',
                        'badge' => 'Starting ₹25 / card',
                        'price' => '₹25',
                        'desc' => 'Standardize government health cards and state schemes with family ID and ABHA number into durable pocket PVC cards.',
                        'features' => [
                            'Official PM-JAY layout compliance',
                            'Crisp QR scan guarantee'
                        ],
                        'action_text' => 'Format Health Card',
                        'action_link' => 'register.html'
                    ],
                    [
                        'id' => 'wallet_topup',
                        'category' => 'wallet',
                        'icon' => 'fa-wallet',
                        'color' => 'green',
                        'title' => 'Instant Wallet & UPI QR Topup',
                        'badge' => '0% Fees (FREE)',
                        'price' => '0% Fees',
                        'desc' => 'Recharge your balance 24x7 using PhonePe, Google Pay, or Paytm. Submit the payment reference for approval within ~2 mins.',
                        'features' => [
                            'Zero gateway fees or convenience charge',
                            'Realtime transaction ledger'
                        ],
                        'action_text' => 'Top Up Wallet',
                        'action_link' => 'register.html'
                    ]
                ]
            ],
            'workflow' => [
                'tag' => 'Rapid Workflow',
                'title' => 'How The Platform Works',
                'desc' => 'From onboarding to downloading print-ready documents in less than 2 minutes.',
                'steps' => [
                    [
                        'step_num' => '01',
                        'title' => 'Create Free Account',
                        'desc' => 'Register your retailer account with Name, Mobile, and Email in under 30 seconds with 0 onboarding fees.'
                    ],
                    [
                        'step_num' => '02',
                        'title' => 'Top Up Wallet',
                        'desc' => 'Scan the Admin UPI QR code, submit the 12-digit UTR reference, and balance is credited rapidly.'
                    ],
                    [
                        'step_num' => '03',
                        'title' => 'Submit Order',
                        'desc' => 'Upload your Aadhaar PDF, enter PAN query details, or submit Voter ID for automated formatting.'
                    ],
                    [
                        'step_num' => '04',
                        'title' => 'Instant HD Download',
                        'desc' => 'Download pixel-perfect 300 DPI CR-80 PDFs directly to your system ready for immediate printing.'
                    ]
                ]
            ],
            'pricing' => [
                'tag' => 'Transparent Pricing',
                'title' => 'Predictable Rates & Turnaround Times',
                'desc' => 'No monthly subscriptions, no lock-ins, and no hidden fees. Pay strictly for the orders you submit.',
                'cards' => [
                    [
                        'title' => 'Aadhaar HD Smart PVC Print',
                        'price' => '₹ 20.00',
                        'speed' => '1 - 2 mins',
                        'format' => 'HD Vector PDF (300 DPI)',
                        'desc' => 'Front & Back CR-80 auto-aligned layout'
                    ],
                    [
                        'title' => 'Lost PAN Find by Aadhaar',
                        'price' => '₹ 30.00',
                        'speed' => '< 30 secs',
                        'format' => 'Verified Number & Status',
                        'desc' => 'Checked manually by our team'
                    ],
                    [
                        'title' => 'Voter ID (e-EPIC) HD Print',
                        'price' => '₹ 20.00',
                        'speed' => '1 - 2 mins',
                        'format' => 'CR-80 PVC Layout',
                        'desc' => 'Election Commission format standard'
                    ],
                    [
                        'title' => 'Ayushman Golden Card Print',
                        'price' => '₹ 25.00',
                        'speed' => '1 - 2 mins',
                        'format' => 'PVC Print Sheet',
                        'desc' => 'PM-JAY beneficiary card formatting'
                    ],
                    [
                        'title' => 'Manual UPI Wallet Recharge',
                        'price' => '0% (FREE)',
                        'speed' => '1 - 2 mins',
                        'format' => 'Direct Wallet Credit',
                        'desc' => 'Google Pay, PhonePe, Paytm, BHIM UPI'
                    ]
                ]
            ],
            'testimonials' => [
                'tag' => 'Verified Feedback',
                'title' => 'Trusted By 12,000+ Cyber Cafe Owners',
                'desc' => 'Hear why CSC operators and digital centers across India rely on Online Digital Service.',
                'items' => [
                    [
                        'quote' => 'Online Digital Service has changed how our Cyber Cafe operates. The PVC card dimensions are always 100% accurate and our customers are delighted with the HD 300 DPI print quality.',
                        'author' => 'Soumya Ranjan Panda',
                        'role' => 'Maa Tarini Cyber Cafe, Bhubaneswar',
                        'initials' => 'SP',
                        'stars' => 5
                    ],
                    [
                        'quote' => 'Finding lost PAN numbers by Aadhaar used to take days. Here we get the result in seconds. The manual UPI wallet recharge is verified within 2 minutes flat. Fantastic portal!',
                        'author' => 'Biswajit Mohapatra',
                        'role' => 'CSC Seva Kendra, Cuttack',
                        'initials' => 'BM',
                        'stars' => 5
                    ],
                    [
                        'quote' => 'Customer support is always active. Whenever there is any query regarding document formatting or wallet, the team resolves it immediately on WhatsApp. Highly recommended!',
                        'author' => 'Anil Kumar Sethi',
                        'role' => 'Digital Print Hub, Sambalpur',
                        'initials' => 'AK',
                        'stars' => 5
                    ]
                ]
            ],
            'faq' => [
                'tag' => 'Got Questions?',
                'title' => 'Frequently Asked Questions',
                'desc' => 'Everything you need to know about wallet recharges, document formats, and turnaround.',
                'items' => [
                    [
                        'question' => 'How fast does the PAN Find by Aadhaar service work?',
                        'answer' => 'PAN recovery by Aadhaar is processed within seconds once submitted. You will immediately see the retrieved PAN number with official status report inside your completed orders dashboard.'
                    ],
                    [
                        'question' => 'How do I recharge my wallet balance?',
                        'answer' => 'Navigate to the Wallet tab in your dashboard, scan the Admin UPI QR Code using Google Pay, PhonePe, or Paytm, enter the 12-digit UTR/Transaction Reference Number, and upload the payment screenshot. Admin verifies and credits your wallet within 1-2 minutes.'
                    ],
                    [
                        'question' => 'Are the downloaded files ready for direct PVC ID Card printing?',
                        'answer' => 'Yes! All generated PDF files are exported strictly in standard ISO/IEC 7810 CR80 dimensions (85.60 × 53.98 mm) at 300 DPI high resolution, perfectly sized for direct printing on Epson, Canon, Magicard, Fargo, or Evolis card printers.'
                    ],
                    [
                        'question' => 'What happens if an order is rejected or data is not found?',
                        'answer' => 'If any order cannot be processed or if data is not found, your wallet points/funds are instantly refunded 100% with a clear rejection reason displayed in your dashboard.'
                    ],
                    [
                        'question' => 'Is customer data safe, secure, and confidential?',
                        'answer' => 'Absolutely. All connections use HTTPS encryption. Uploaded documents are kept in private storage that is not publicly accessible, and only your account and our processing staff can open them.'
                    ]
                ]
            ],
            'cta' => [
                'badge' => '30 Seconds Onboarding',
                'title' => 'Ready to Supercharge Your Citizen Services?',
                'subtitle' => 'Join thousands of cyber cafe operators and CSC centers saving hours daily with instant HD PVC prints, lost PAN recovery, and 0-delay UPI recharges.',
                'primary_text' => 'Create Free Account',
                'primary_link' => 'register.html',
                'secondary_text' => 'User Login',
                'secondary_link' => 'login.html'
            ],
            'footer' => [
                'slogan' => "India's foremost digital print formatting and PAN retrieval suite designed for retailers, cyber cafes, and customer service centers.",
                'phone' => '+91 97358 00298',
                'whatsapp' => '+91 97358 00298',
                'email' => 'mominulonlinetelicom@gmail.com',
                'hours' => 'Mon - Sat: 8:00 AM - 10:00 PM',
                'copyright' => '© 2026 Online Digital Service. All rights reserved. Built for Indian Digital Retailers.',
                'facebook_link' => '#',
                'twitter_link' => '#',
                'whatsapp_link' => '#',
                'telegram_link' => '#',
                'privacy_url' => 'privacy.html',
                'terms_url' => 'terms.html',
                'refund_url' => 'refund.html'
            ]
        ];
    }
}
