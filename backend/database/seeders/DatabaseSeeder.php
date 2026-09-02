<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Service;
use App\Models\Setting;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Super Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@utkalprint.com'],
            [
                'name' => 'Utkal Super Admin',
                'phone' => '9876543210',
                'password' => Hash::make('Admin@123'),
                'role' => 'admin',
                'wallet_balance' => 999999.00,
                'status' => 'active',
            ]
        );

        // 2. Create Demo Customer (with ₹105 balance matching screenshot)
        $user = User::firstOrCreate(
            ['email' => 'demo@utkalprint.com'],
            [
                'name' => 'Aritra Mondal',
                'phone' => '9123456789',
                'password' => Hash::make('User@123'),
                'role' => 'user',
                'wallet_balance' => 105.00,
                'status' => 'active',
            ]
        );

        // Initial demo wallet transaction record
        WalletTransaction::firstOrCreate(
            ['user_id' => $user->id, 'description' => 'Initial Demo Wallet Balance'],
            [
                'type' => 'credit',
                'amount' => 105.00,
                'balance_after' => 105.00,
                'status' => 'approved',
                'approved_by' => $admin->id,
                'utr_number' => 'INIT-DEMO-105',
            ]
        );

        // 3. Create Default Print & PAN Services
        $services = [
            [
                'category' => 'print',
                'name' => 'Aadhaar Smart Card PVC Print',
                'slug' => 'aadhaar-smart-card-pvc-print',
                'description' => 'Ultra HD PVC Aadhaar card printing with waterproof lamination and barcode/QR verification.',
                'price' => 50.00,
                'required_fields' => [
                    ['name' => 'aadhaar_number', 'label' => '12-Digit Aadhaar Number', 'type' => 'text', 'placeholder' => 'xxxx xxxx xxxx', 'required' => true],
                    ['name' => 'applicant_name', 'label' => 'Name as on Aadhaar', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'aadhaar_file', 'label' => 'Upload Aadhaar e-PDF / Scan', 'type' => 'file', 'required' => true]
                ],
                'is_active' => true,
            ],
            [
                'category' => 'print',
                'name' => 'Voter ID PVC Card Print',
                'slug' => 'voter-id-pvc-card-print',
                'description' => 'National Voter ID smart card print with high clarity and EPIC number check.',
                'price' => 40.00,
                'required_fields' => [
                    ['name' => 'epic_number', 'label' => 'Voter EPIC Number', 'type' => 'text', 'placeholder' => 'e.g. WBF1234567', 'required' => true],
                    ['name' => 'applicant_name', 'label' => 'Voter Name', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'voter_file', 'label' => 'Upload Voter Slip/PDF', 'type' => 'file', 'required' => false]
                ],
                'is_active' => true,
            ],
            [
                'category' => 'print',
                'name' => 'PAN Card PVC Print',
                'slug' => 'pan-card-pvc-print',
                'description' => 'Original standard NSDL / UTIITSL format PAN PVC card reprint.',
                'price' => 50.00,
                'required_fields' => [
                    ['name' => 'pan_number', 'label' => '10-Digit PAN Number', 'type' => 'text', 'placeholder' => 'e.g. ABCDE1234F', 'required' => true],
                    ['name' => 'applicant_name', 'label' => 'Cardholder Name', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'pan_file', 'label' => 'Upload e-PAN PDF', 'type' => 'file', 'required' => false]
                ],
                'is_active' => true,
            ],
            [
                'category' => 'print',
                'name' => 'Ayushman Bharat Card Print',
                'slug' => 'ayushman-bharat-card-print',
                'description' => 'PM-JAY Health card high-speed PVC plastic print.',
                'price' => 30.00,
                'required_fields' => [
                    ['name' => 'ayushman_id', 'label' => 'Ayushman PM-JAY Card No', 'type' => 'text', 'placeholder' => 'Card ID / ABHA No', 'required' => true],
                    ['name' => 'beneficiary_name', 'label' => 'Beneficiary Name', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'card_file', 'label' => 'Upload PM-JAY Slip / PDF', 'type' => 'file', 'required' => false]
                ],
                'is_active' => true,
            ],
            [
                'category' => 'pan_find',
                'name' => 'Instant PAN Find by Aadhaar Number',
                'slug' => 'instant-pan-find-by-aadhaar',
                'description' => 'Retrieve lost PAN number and details directly using 12-digit Aadhaar number.',
                'price' => 25.00,
                'required_fields' => [
                    ['name' => 'aadhaar_number', 'label' => '12-Digit Aadhaar Number', 'type' => 'text', 'placeholder' => 'e.g. 1234 5678 9012', 'required' => true],
                    ['name' => 'applicant_name', 'label' => 'Name as per Aadhaar', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'dob', 'label' => 'Date of Birth', 'type' => 'date', 'required' => false]
                ],
                'is_active' => true,
            ],
            [
                'category' => 'pan_find',
                'name' => 'Manual PAN Find by Demographics',
                'slug' => 'manual-pan-find-by-demographics',
                'description' => 'Find PAN card number by Person Name, Father Name, and Date of Birth.',
                'price' => 35.00,
                'required_fields' => [
                    ['name' => 'applicant_name', 'label' => 'Full Applicant Name', 'type' => 'text', 'placeholder' => 'Full Name', 'required' => true],
                    ['name' => 'father_name', 'label' => 'Father\'s Name', 'type' => 'text', 'placeholder' => 'Father Name', 'required' => true],
                    ['name' => 'dob', 'label' => 'Date of Birth', 'type' => 'date', 'required' => true],
                    ['name' => 'gender', 'label' => 'Gender', 'type' => 'text', 'placeholder' => 'Male / Female / Other', 'required' => false]
                ],
                'is_active' => true,
            ],
        ];

        foreach ($services as $srv) {
            Service::updateOrCreate(['slug' => $srv['slug']], $srv);
        }

        // 4. Default Settings
        Setting::set('upi_id', 'utkalprint@upi');
        Setting::set('upi_name', 'Utkal Print Portal');
        Setting::set('notice_board', 'Welcome to Utkal Print Portal! Instant PVC card printing and PAN verification services are now live. Recharge wallet for fast 1-click orders.');
    }
}
