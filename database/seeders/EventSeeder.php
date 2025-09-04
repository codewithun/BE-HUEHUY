<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Event;
use App\Models\Community;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get first community if exists, otherwise create one
        $community = Community::first();
        if (!$community) {
            $community = Community::create([
                'name' => 'Tech Community',
                'description' => 'A community for tech enthusiasts',
                'logo' => null,
            ]);
        }

        $events = [
            [
                'title' => 'Laravel Workshop 2025',
                'subtitle' => 'Learn Modern Laravel Development',
                'organizer_name' => 'Tech Indonesia',
                'organizer_type' => 'Organization',
                'date' => '2025-10-15',
                'time' => '09:00 - 17:00',
                'location' => 'Jakarta Convention Center',
                'address' => 'Jl. Gatot Subroto, Jakarta Selatan',
                'category' => 'Workshop',
                'participants' => 25,
                'max_participants' => 100,
                'price' => 'Rp 500.000',
                'description' => 'Comprehensive Laravel workshop covering the latest features and best practices in modern web development.',
                'requirements' => 'Basic PHP knowledge, Laptop with development environment',
                'schedule' => '09:00-10:30 Introduction\n10:30-12:00 Laravel Basics\n13:00-15:00 Advanced Features\n15:00-17:00 Project Building',
                'prizes' => 'Certificate of completion, Laravel merchandise',
                'contact_phone' => '+62812345678',
                'contact_email' => 'workshop@techindonesia.com',
                'tags' => 'laravel,php,web development,workshop',
                'community_id' => $community->id,
            ],
            [
                'title' => 'React Native Bootcamp',
                'subtitle' => 'Build Mobile Apps with React Native',
                'organizer_name' => 'Mobile Dev Community',
                'organizer_type' => 'Community',
                'date' => '2025-11-20',
                'time' => '10:00 - 16:00',
                'location' => 'Bandung Digital Valley',
                'address' => 'Jl. Ganesha No. 10, Bandung',
                'category' => 'Bootcamp',
                'participants' => 15,
                'max_participants' => 50,
                'price' => 'Free',
                'description' => 'Learn to build cross-platform mobile applications using React Native framework.',
                'requirements' => 'JavaScript knowledge, React experience preferred',
                'schedule' => '10:00-11:30 React Native Intro\n11:30-13:00 Components & Navigation\n14:00-16:00 Building Complete App',
                'prizes' => 'Certificate, Networking opportunities',
                'contact_phone' => '+62887654321',
                'contact_email' => 'bootcamp@mobiledev.id',
                'tags' => 'react native,mobile,javascript,bootcamp',
                'community_id' => $community->id,
            ],
            [
                'title' => 'AI & Machine Learning Summit',
                'subtitle' => 'Exploring the Future of AI Technology',
                'organizer_name' => 'AI Indonesia',
                'organizer_type' => 'Organization',
                'date' => '2025-12-05',
                'time' => '08:00 - 18:00',
                'location' => 'Surabaya Convention Hall',
                'address' => 'Jl. Pemuda No. 1, Surabaya',
                'category' => 'Conference',
                'participants' => 150,
                'max_participants' => 300,
                'price' => 'Rp 750.000',
                'description' => 'Join industry experts and researchers to discuss the latest trends and innovations in AI and Machine Learning.',
                'requirements' => 'Basic understanding of programming, Interest in AI/ML',
                'schedule' => '08:00-09:00 Registration\n09:00-12:00 Keynote Sessions\n13:00-15:00 Technical Workshops\n15:00-18:00 Panel Discussions',
                'prizes' => 'Certificate, AI toolkit, Networking dinner',
                'contact_phone' => '+62856789012',
                'contact_email' => 'summit@aiindonesia.org',
                'tags' => 'artificial intelligence,machine learning,conference,technology',
                'community_id' => null, // No community association
            ],
            [
                'title' => 'Startup Pitch Competition',
                'subtitle' => 'Present Your Innovative Ideas',
                'organizer_name' => 'Startup Incubator',
                'organizer_type' => 'Incubator',
                'date' => '2025-09-25',
                'time' => '13:00 - 20:00',
                'location' => 'Innovation Hub',
                'address' => 'Jl. Sudirman Kav. 52-53, Jakarta',
                'category' => 'Competition',
                'participants' => 8,
                'max_participants' => 20,
                'price' => 'Free',
                'description' => 'Pitch your startup idea to a panel of investors and industry experts for a chance to win funding.',
                'requirements' => 'Prepared pitch deck, Business plan, Prototype (optional)',
                'schedule' => '13:00-14:00 Registration & Networking\n14:00-18:00 Pitch Presentations\n18:00-20:00 Judging & Awards',
                'prizes' => 'Winner: $10,000 funding, Runner-up: $5,000, Mentorship opportunities',
                'contact_phone' => '+62823456789',
                'contact_email' => 'pitch@startupincubator.id',
                'tags' => 'startup,pitch,competition,funding,entrepreneurship',
                'community_id' => null,
            ],
        ];

        foreach ($events as $eventData) {
            Event::create($eventData);
        }
    }
}
