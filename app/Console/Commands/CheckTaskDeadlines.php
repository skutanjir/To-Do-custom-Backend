<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Todo;
use App\Models\Notification;
use Carbon\Carbon;

class CheckTaskDeadlines extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for tasks due in 1, 2, or 3 days and create notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $daysToCheck = [1, 2, 3];
        
        foreach ($daysToCheck as $days) {
            $date = Carbon::now()->addDays($days)->toDateString();
            
            $todos = Todo::whereDate('deadline', $date)
                ->where('is_completed', false)
                ->get();
            
            foreach ($todos as $todo) {
                if ($todo->user_id) {
                    $this->createNotification($todo, $days);
                }
                
                // If it's a team task, notify all members? 
                // For now, let's keep it simple: notify the owner.
                // If team tasks belong to multiple people, we might need to iterate members.
            }
        }

        $this->info('Deadline checks completed.');
    }

    protected function createNotification($todo, $days)
    {
        $message = "Ongoing Task Reminder: \"{$todo->judul}\" is due in {$days} day(s)!";
        $type = "reminder_h{$days}";

        // Prevent duplicate notifications for the same day/task
        $exists = Notification::where('user_id', $todo->user_id)
            ->where('team_id', $todo->team_id)
            ->where('type', $type)
            ->whereDate('created_at', Carbon::today())
            ->exists();

        if (!$exists) {
            Notification::create([
                'user_id' => $todo->user_id,
                'team_id' => $todo->team_id,
                'type' => $type,
                'message' => $message,
            ]);
            
            $this->line("Notification created for: {$todo->judul} (H-{$days})");
        }
    }
}
