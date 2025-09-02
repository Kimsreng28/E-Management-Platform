<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\FAQCategory;
use App\Models\FAQ;

class FAQSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create FAQ Categories
        $categories = [
            [
                'name_en' => 'General Questions',
                'name_kh' => 'សំណួរទូទៅ',
                'order' => 1,
                'is_active' => true
            ],
            [
                'name_en' => 'Products & Services',
                'name_kh' => 'ផលិតផល និងសេវាកម្ម',
                'order' => 2,
                'is_active' => true
            ],
            [
                'name_en' => 'Orders & Shipping',
                'name_kh' => 'ការបញ្ជាទិញ និងការដឹកជញ្ជូន',
                'order' => 3,
                'is_active' => true
            ],
            [
                'name_en' => 'Payments & Pricing',
                'name_kh' => 'ការទូទាត់ និងតម្លៃ',
                'order' => 4,
                'is_active' => true
            ],
        ];

        foreach ($categories as $category) {
            FAQCategory::create($category);
        }

        // Create FAQs
        $faqs = [
            [
                'question_en' => 'How quickly do you respond to inquiries?',
                'question_kh' => 'តើអ្នកឆ្លើយតបទៅនឹងការស្វែងរកយ៉ាងរហ័សប៉ុណ្ណា?',
                'answer_en' => 'We typically respond to all inquiries within 24 hours during business days. Urgent requests are prioritized.',
                'answer_kh' => 'ជាទូទៅយើងឆ្លើយតបទៅនឹងការស្វែងរកទាំងអស់ក្នុងរយៈពេល 24 ម៉ោងក្នុងអំឡុងពេលថ្ងៃធ្វើការ។ ការស្នើសុំដែលចាំបាច់ត្រូវបានផ្តល់អាទិភាព។',
                'category_id' => 1,
                'order' => 1,
                'is_active' => true
            ],
            [
                'question_en' => 'What are your business hours?',
                'question_kh' => 'តើម៉ោងអាជីវកម្មរបស់អ្នកគឺជាអ្វី?',
                'answer_en' => 'Our support team is available Monday to Friday, 8:00 AM to 6:00 PM. We\'re closed on weekends and public holidays.',
                'answer_kh' => 'ក្រុមជំនួយរបស់យើងគឺមានពីថ្ងៃចន្ទដល់ថ្ងៃសុក្រ ពីម៉ោង 8:00 ព្រឹក ដល់ 6:00 ល្ងាច។ យើងបិទក្នុងថ្ងៃសប្តាហ៍និងថ្ងៃឈប់សម្រាកសាធារណៈ។',
                'category_id' => 1,
                'order' => 2,
                'is_active' => true
            ],
            [
                'question_en' => 'Do you offer custom solutions?',
                'question_kh' => 'តើអ្នកផ្តល់នូវដំណោះស្រាយផ្ទាល់ខ្លួនឬទេ?',
                'answer_en' => 'Yes, we specialize in custom solutions tailored to your specific business needs. Contact us to discuss your requirements.',
                'answer_kh' => 'បាទ/ចាស យើងឯកទេសក្នុងការផ្តល់ដំណោះស្រាយផ្ទាល់ខ្លួនដែលត្រូវបានកំណត់ជាពិសេសសម្រាប់តម្រូវការអាជីវកម្មរបស់អ្នក។ សូមទាក់ទងមកយើងខ្ញុំដើម្បីពិភាក្សាអំពីតម្រូវការរបស់អ្នក។',
                'category_id' => 2,
                'order' => 1,
                'is_active' => true
            ],
            [
                'question_en' => 'Where are you located?',
                'question_kh' => 'តើអ្នកស្ថិតនៅទីណា?',
                'answer_en' => 'Our main office is in Phnom Penh, Cambodia. We serve clients globally and have team members across different time zones.',
                'answer_kh' => 'ការិយាល័យចម្បងរបស់យើងស្ថិតនៅភ្នំពេញ កម្ពុជា។ យើងផ្តល់សេវាដល់អតិថិជនជុំវិញពិភពលោក ហើយមានសមាជិកក្រុមនៅទូទាំងតំបន់ពេលវេលាផ្សេងៗគ្នា។',
                'category_id' => 1,
                'order' => 3,
                'is_active' => true
            ],
        ];

        foreach ($faqs as $faq) {
            FAQ::create($faq);
        }
    }
}
