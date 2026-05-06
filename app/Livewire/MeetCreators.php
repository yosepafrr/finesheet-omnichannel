<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app', ['title' => 'Meet the Creators'])]
class MeetCreators extends Component
{
    public $creators = [
        [
            'name' => 'Yosep Adriana Fauzi R',
            'role' => 'Undergraduate Computer Science Student',
            'image' => 'images/yosep.jpg',
            'bio' => 'Arsitek di balik logika sistem backend dan manajemen database. Mengubah kopi menjadi kode yang efisien.',
            'stack' => ['Laravel', 'PostgreSQL', 'Livewire'],
            'social' => [
                'github' => 'https://github.com/yosepafrr',
                'linkedin' => 'https://linkedin.com/in/',
                'email' => 'yosep.adrianaa@gmail.com'
            ]
        ],
        [
            'name' => 'Nama Creator 2',
            'role' => 'UI/UX & Frontend Dev',
            'image' => 'https://ui-avatars.com/api/?name=Creator+Two&background=1e2f50&color=fff&size=512', 
            'bio' => 'Pencipta antarmuka yang memanjakan mata. Fokus pada pengalaman pengguna yang mulus dan interaktif.',
            'stack' => ['Tailwind', 'Alpine.js', 'Figma'],
            'social' => [
                'github' => 'https://github.com/',
                'linkedin' => 'https://linkedin.com/in/',
                'email' => 'creator2@example.com'
            ]
        ]
    ];

    public function render()
    {
        return view('livewire.meet-creators');
    }
}