<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ChatbotFlow;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        ChatbotFlow::updateOrCreate(
            ['key' => 'default'],
            ['steps' => [
                ['key'=>'greeting','question'=>"Hi! 👋 I'm Seben Assistant. Can I help you get started?",'placeholder'=>"Click 'Yes' to continue",'type'=>'button','is_button'=>true],
                ['key'=>'name','question'=>"What's your name?",'placeholder'=>"Enter your name",'type'=>'text'],
                ['key'=>'phone','question'=>"Great, {name}. What's your phone number?",'placeholder'=>"Enter your phone number",'type'=>'phone'],
                ['key'=>'email','question'=>"Lastly, your email?",'placeholder'=>"Enter your email address",'type'=>'email'],
            ], 'is_active'=>true]
        );
    }
}
