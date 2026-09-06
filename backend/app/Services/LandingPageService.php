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
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return array_replace_recursive($this->getDefaultConfig(), $decoded);
                }
            }
            return $this->getDefaultConfig();
        });
    }

    /**
     * Save the updated landing page configuration
     */
    public function saveConfig(array $data): array
    {
        $merged = array_replace_recursive($this->getDefaultConfig(), $data);
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
     * System default landing page content
     */
    public function getDefaultConfig(): array
    {
        return [
            'visibility' => [
                'hero' => true,
                'services' => true,
                'pricing' => true,
                'workflow' => true,
                'testimonials' => true,
                'faq' => true,
                'cta' => true,
            ],
            'hero' => [
                'badge_icon' => 'fa-bolt-lightning',
                'badge_text' => 'Instant Digital Seva Engine 2026 • Razorpay-Grade Speed',
                'title_highlight' => 'Instant Document',
                'title_rest' => 'Printing & PAN Recovery Suite',
                'subtitle' => 'High-definition Aadhaar PVC card formatting, instant lost PAN recovery by Aadhaar, Voter ID prints, and seamless zero-delay UPI wallet recharges built specifically for Cyber Cafes and CSC Retailers.',
                'cta_primary_text' => 'Launch Portal Free',
                'cta_primary_link' => 'register.html',
                'cta_secondary_text' => 'User Login',
                'cta_secondary_link' => 'login.html',
                'trust_items' => [
                    ['text' => '50,000+ PVC Cards Printed', 'icon' => 'fa-circle-check'],
                    ['text' => 'Instant UPI Approvals', 'icon' => 'fa-bolt'],
                    ['text' => '256-Bit Bank Grade Safe', 'icon' => 'fa-lock'],
                ],
            ],
            'services' => [
                'tag' => 'Enterprise Suite',
                'title' => 'Everything Your Cyber Cafe Needs in One Place',
                'desc' => 'Engineered for Digital Retailers, CSC Centers, and Cyber Cafes to fulfill citizen requests with lightning turnaround, highest resolution, and maximum profit margins.',
                'items' => [
                    [
                        'id' => 'aadhaar_pvc',
                        'icon' => 'fa-id-card',
                        'title' => 'Aadhaar PVC Print',
                        'badge' => '0.8s Rendering',
                        'desc' => 'Instant front and back dual-sided layout generator conforming strictly to standard 85.60 x 53.98 mm ISO dimensions. Upload citizen PDF and get crystal-clear 300 DPI layout ready for PVC printing.',
                        'price' => '₹15',
                        'features' => [
                            '100% Exact 85.60 x 53.98 mm ISO Specs',
                            '300 DPI High Definition Print Output',
                            'Instant Auto-Crop Front & Back Layout'
                        ]
                    ],
                    [
                        'id' => 'pan_find',
                        'icon' => 'fa-magnifying-glass-location',
                        'title' => 'Instant PAN Find by Aadhaar',
                        'badge' => '30s Recovery',
                        'desc' => 'Customer lost their PAN card? Enter only their 12-digit Aadhaar number to fetch their official 10-digit PAN number securely in real-time from official government databases.',
                        'price' => '₹25',
                        'features' => [
                            'Validates against NSDL & UTI databases',
                            'Instant 10-Digit PAN Number Retrieval',
                            'Instant Direct Print Ready'
                        ]
                    ],
                    [
                        'id' => 'wallet_topup',
                        'icon' => 'fa-wallet',
                        'title' => 'Instant Wallet & UPI Topup',
                        'badge' => 'Instant Credit',
                        'desc' => 'Top up balance immediately via PhonePe, Google Pay, Paytm, or BHIM. Zero payment gateway deduction fees. Wallet updates on confirmation for uninterrupted citizen services.',
                        'price' => '0% Surcharge',
                        'features' => [
                            'Dynamic UPI QR for PhonePe, GPay, Paytm',
                            'Zero Transaction Gateway Fees',
                            'Immediate Balance Update for Printing'
                        ]
                    ],
                    [
                        'id' => 'ayushman',
                        'icon' => 'fa-heart-pulse',
                        'title' => 'Ayushman Bharat PVC Card',
                        'badge' => 'HD Format',
                        'desc' => 'Standardize PMJAY health cards into crisp print-ready layouts with embedded QR code enhancement. Perfect color depth and card-holder clarity.',
                        'price' => '₹15',
                        'features' => [
                            'Automated PVC Card Dimensions',
                            'High Contrast QR Code Generation',
                            'No Photoshop or Manual Editing Needed'
                        ]
                    ],
                    [
                        'id' => 'voter_id',
                        'icon' => 'fa-person-booth',
                        'title' => 'Voter ID (EPIC) Print',
                        'badge' => 'Direct Print',
                        'desc' => 'Generate clean dual-sided voter identity cards with official hologram placement margin. Fast formatting optimized for inkjet PVC trays.',
                        'price' => '₹15',
                        'features' => [
                            'Pre-Formatted Front & Back Layout',
                            'Crystal-Clear Text & Hologram Placement',
                            'Thermal & Inkjet PVC Tray Ready'
                        ]
                    ],
                ]
            ],
            'pricing' => [
                'tag' => 'Transparent Pricing',
                'title' => 'Predictable, Low Unit Costs for Higher Margins',
                'desc' => 'No recurring subscriptions. No hidden onboarding fees. Pay strictly per print request from your pre-funded prepaid wallet.',
                'cards' => [
                    [
                        'title' => 'Aadhaar PVC Card',
                        'price' => '₹15',
                        'unit' => '/ card',
                        'badge' => 'Most Popular',
                        'desc' => 'Front & back auto-arranged ISO card with crop guides.',
                        'speed' => 'Instant (0.8s)',
                        'format' => '300 DPI HD PDF'
                    ],
                    [
                        'title' => 'PAN Find by Aadhaar',
                        'price' => '₹25',
                        'unit' => '/ query',
                        'badge' => 'Highest Margin',
                        'desc' => 'Instant NSDL & UTI matching for lost PAN retrieval.',
                        'speed' => 'Under 30 Seconds',
                        'format' => 'Official 10-Digit Number'
                    ],
                    [
                        'title' => 'Voter ID (EPIC) Print',
                        'price' => '₹15',
                        'unit' => '/ card',
                        'badge' => 'Fast SLA',
                        'desc' => 'National Voter Services layout ready for PVC trays.',
                        'speed' => 'Instant Print Ready',
                        'format' => 'PVC Dual-Sided Layout'
                    ],
                    [
                        'title' => 'Ayushman PVC Card',
                        'price' => '₹15',
                        'unit' => '/ card',
                        'badge' => 'Healthcare ID',
                        'desc' => 'Golden card print layout with barcode sharpening.',
                        'speed' => 'Instant HD Format',
                        'format' => 'Standard CR80 Size'
                    ]
                ]
            ],
            'workflow' => [
                'tag' => 'Instant Workflow',
                'title' => 'How Instant Online Seva Works in 3 Simple Steps',
                'steps' => [
                    [
                        'step_num' => '01',
                        'title' => 'Create Account & Top Up Wallet',
                        'desc' => 'Register your user account with Name, Mobile, and Email in under 30 seconds with 0 onboarding fees. Scan dynamic UPI QR to credit balance instantly.'
                    ],
                    [
                        'step_num' => '02',
                        'title' => 'Upload Citizen Data or Enter Details',
                        'desc' => 'Upload citizen PDF file or enter Aadhaar number for lost PAN search. Our AI engine processes and structures data automatically in milliseconds.'
                    ],
                    [
                        'step_num' => '03',
                        'title' => 'Instant 1-Click Generation & Print',
                        'desc' => 'Download ISO-standard 300 DPI dual-sided PDF layout ready for direct printing on any PVC inkjet card printer, Epson tray, or thermal machine.'
                    ]
                ]
            ],
            'testimonials' => [
                'tag' => 'Retailer Reviews',
                'title' => 'Trusted by 12,000+ Operators Across India',
                'desc' => 'Hear why CSC operators and digital centers across India rely on Instant Online Seva.',
                'items' => [
                    [
                        'quote' => 'Instant Online Seva has changed how our Cyber Cafe operates. The PVC card dimensions are always 100% accurate and our customers are delighted with the HD 300 DPI print quality.',
                        'author' => 'Rakesh Mohapatra',
                        'role' => 'Digital Seva Kendra, Cuttack',
                        'stars' => 5
                    ],
                    [
                        'quote' => 'PAN Find feature alone has recovered 150+ lost PAN cards for our local customers this month. The UPI instant wallet top-up saves so much time.',
                        'author' => 'Amitava Ghosh',
                        'role' => 'CSC Center, Kolkata',
                        'stars' => 5
                    ],
                    [
                        'quote' => 'We run 3 Epson L805 PVC tray printers. The auto-arranged front and back layout prints in 1 click without any Photoshop scaling errors.',
                        'author' => 'Pooja Verma',
                        'role' => 'Cyber World, Patna',
                        'stars' => 5
                    ]
                ]
            ],
            'faq' => [
                'tag' => 'Common Questions',
                'title' => 'Frequently Asked Questions',
                'items' => [
                    [
                        'question' => 'What equipment or printer do I need to print PVC cards?',
                        'answer' => 'Any standard inkjet printer with a PVC card ID tray (like Epson L805, L850, L8050, Canon G1010/G2010 series) or specialized thermal card printers. Our generated PDFs adhere strictly to standard CR80 ISO dimensions (85.60 x 53.98 mm).'
                    ],
                    [
                        'question' => 'How does PAN Find work if the customer lost their card?',
                        'answer' => 'Simply enter the citizen\'s 12-digit Aadhaar number. Our secure API connects to NSDL/UTI databases to verify and retrieve the registered 10-digit PAN number within seconds.'
                    ],
                    [
                        'question' => 'How do I add balance to my wallet?',
                        'answer' => 'Click on \'Recharge Wallet via UPI\', choose your top-up amount (e.g., ₹100, ₹500, ₹1000), and scan the dynamic UPI QR code with any app like PhonePe, Google Pay, or Paytm. Balance reflects in your wallet immediately.'
                    ],
                    [
                        'question' => 'Is citizen data secure and confidential?',
                        'answer' => 'Absolutely. We adhere to stringent 256-bit encryption standards. Uploaded documents and processed records are stored securely in protected storage and accessible only by your verified account.'
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
                'slogan' => 'India\'s foremost digital print formatting and PAN retrieval suite designed for retailers, cyber cafes, and customer service centers.',
                'phone' => '+91 98765 43210',
                'whatsapp' => '+91 98765 43210',
                'email' => 'support@utkalprint.com',
                'hours' => 'Mon - Sat: 8:00 AM - 10:00 PM',
                'copyright' => '© 2026 Instant Online Seva. All rights reserved. Built for Indian Digital Retailers.',
                'facebook_link' => '#',
                'twitter_link' => '#',
                'whatsapp_link' => '#',
                'telegram_link' => '#'
            ]
        ];
    }
}
