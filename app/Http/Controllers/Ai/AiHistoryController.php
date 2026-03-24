<?php
// app/Http/Controllers/AiHistoryController.php
namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiHistoryController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();
        $deviceId = $request->header('X-Device-ID') ?? $request->input('device_id');

        $chats = AiChat::where(function($q) use ($userId, $deviceId) {
                if ($userId && $deviceId) {
                    $q->where('user_id', $userId)
                      ->orWhere(function($sq) use ($deviceId) {
                          $sq->whereNull('user_id')->where('device_id', $deviceId);
                      });
                } else if ($userId) {
                    $q->where('user_id', $userId);
                } else if ($deviceId) {
                    $q->whereNull('user_id')->where('device_id', $deviceId);
                } else {
                    $q->whereRaw('1=0');
                }
            })
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return response()->json($chats);
    }

    public function show(AiChat $aiChat)
    {
        $this->authorize('view', $aiChat);

        return response()->json([
            'chat' => $aiChat,
            'messages' => $aiChat->messages()->orderBy('created_at', 'asc')->paginate(50)
        ]);
    }

    public function destroy(AiChat $aiChat)
    {
        $this->authorize('delete', $aiChat);
        $aiChat->delete();

        return response()->json(['message' => 'Chat history deleted.']);
    }

    public function clear(Request $request)
    {
        $userId = Auth::id();
        $deviceId = $request->header('X-Device-ID') ?? $request->input('device_id');

        $query = AiChat::query();
        if ($userId) {
            $query->where('user_id', $userId);
        } else if ($deviceId) {
            $query->where('user_id', null)->where('device_id', $deviceId);
        } else {
            return response()->json(['message' => 'Identity required to clear history.'], 400);
        }

        $query->delete();

        return response()->json(['message' => 'All chat history cleared for this device/user.']);
    }
}
