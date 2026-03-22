<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\LocalAiEngine;
use PHPUnit\Framework\Attributes\Test;

class LocalAiTest extends TestCase
{
    #[Test]
    public function indonesian_general_chat()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('apa kabar');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('baik', $data['message']);
    }

    #[Test]
    public function english_general_chat()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('how are you today?');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('doing well', $data['message']);
    }

    #[Test]
    public function javanese_greeting()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('piye kabar, jarvis?');

        $this->assertNotNull($result, 'Engine result should not be null');
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('Senang bisa melayani', $data['message']);
    }

    #[Test]
    public function sundanese_greeting()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('kumaha damang?');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('Senang bisa melayani', $data['message']);
    }

    #[Test]
    public function betawi_greeting()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('aye mau tanya nih');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertNotEmpty($data['message']);
    }

    #[Test]
    public function simple_task_creation()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('buat tugas beli susu');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertNotNull($data['action'], 'Action should not be null for task creation');
        $this->assertEquals('create_task', $data['action']['type']);
        $this->assertEquals('Beli susu', $data['action']['data']['judul']);
    }

    #[Test]
    public function task_with_priority()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('tambah tugas penting: laporan bulanan');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertNotNull($data['action'], 'Action should not be null for prioritized task');
        $this->assertEquals('high', $data['action']['data']['priority']);
        $this->assertStringContainsString('Laporan bulanan', $data['action']['data']['judul']);
    }

    #[Test]
    public function eisenhower_matrix_trigger()
    {
        $task = (object)[
            'judul' => 'Beli susu',
            'priority' => 'high',
            'deadline' => now()->addDay()->format('Y-m-d H:i:s'),
            'is_completed' => false
        ];
        $engine = new LocalAiEngine(collect([$task]), 'User');
        $result = $engine->handle('tampilkan matriks eisenhower');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertNotNull($data['action'], 'Action should not be null for eisenhower matrix');
        $this->assertEquals('search_tasks', $data['action']['type']);
        $this->assertEquals('eisenhower', $data['action']['data']['view']);
    }

    #[Test]
    public function developer_info_locale()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        $result = $engine->handle('siapa pembuatmu?');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('dikembangkan', $data['message']);
    }

    #[Test]
    public function recognizes_memory_nickname_intent()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->expects($this->once())->method('setNickname')->with('fajar');
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('panggil aku fajar');

        $this->assertNotNull($result);
        $this->assertEquals('remember_preference', $result['intent']);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('fajar', $data['message']);
    }

    #[Test]
    public function recognizes_recall_memory_intent()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->method('getNickname')->willReturn('fajar');
        $mockMemory->method('getAllFacts')->willReturn([]);
        $mockMemory->method('recallAll')->willReturn([]);
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('apa yang kamu ingat tentang aku?');

        $this->assertNotNull($result);
        $this->assertEquals('recall_memory', $result['intent']);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('fajar', $data['message']);
    }

    #[Test]
    public function recognizes_forget_memory_intent()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->expects($this->once())->method('forget')->with('preference', 'nickname');
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('lupakan nama saya');

        $this->assertNotNull($result);
        $this->assertEquals('forget_memory', $result['intent']);
    }

    #[Test]
    public function recognizes_memory_stats_intent()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->method('getStats')->willReturn([
            'total' => 10, 'preferences' => 2, 'corrections' => 1,
            'patterns' => 3, 'facts' => 2, 'contexts' => 2
        ]);
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('status memori kamu');

        $this->assertNotNull($result);
        $this->assertEquals('memory_stats', $result['intent']);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('10', $data['message']);
    }

    #[Test]
    public function recognizes_language_switch_intent()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->expects($this->once())->method('setPreferredLanguage')->with('en');
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('ganti bahasa ke inggris');

        $this->assertNotNull($result);
        $this->assertEquals('switch_language', $result['intent']);
        $data = json_decode($result['content'], true);
        $this->assertStringContainsString('English', $data['message']);
    }

    #[Test]
    public function verifies_synonym_expansion_and_intent()
    {
        $engine = new LocalAiEngine(collect([]), 'User');
        
        // "gue" -> "saya", "mau" -> "ingin", "bikin" -> "buat"
        $result = $engine->handle('gue mau bikin tugas: olahraga pagi');
        // dd($result['intent'], json_decode($result['content'], true)['message']);
        $this->assertNotNull($result);
        $this->assertEquals('create', $result['intent']);
        $data = json_decode($result['content'], true);
        $this->assertEquals('create_task', $data['action']['type']);
        $this->assertEquals('Olahraga pagi', $data['action']['data']['judul']);
    }

    #[Test]
    public function verifies_multi_language_t_method_switching()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->method('getPersonality')->willReturn(['preferred_lang' => 'en']);
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('how are you');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        // Should return English response
        $this->assertStringContainsString('doing well', $data['message']);
    }

    #[Test]
    public function verifies_dialect_specific_responses()
    {
        $mockMemory = $this->createMock(\App\Services\AiMemoryService::class);
        $mockMemory->method('getPersonality')->willReturn(['preferred_lang' => 'jv']);
        
        $engine = new LocalAiEngine(collect([]), 'User', [], $mockMemory);
        $result = $engine->handle('apa kabar');

        $this->assertNotNull($result);
        $data = json_decode($result['content'], true);
        // Should return Javanese greeting response if available in respondGeneralChat
        // "Nggih, User. Wonten malih..." is the generic fallback for Javanese
        $this->assertNotEmpty($data['message']);
    }
}
