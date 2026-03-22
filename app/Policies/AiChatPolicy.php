<?php
// app/Policies/AiChatPolicy.php
namespace App\Policies;

use App\Models\AiChat;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AiChatPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, AiChat $aiChat): bool
    {
        // If chat has a user_id, must match the authenticated user
        if ($aiChat->user_id) {
            return $user && $user->id === $aiChat->user_id;
        }

        // If it's a guest chat, it must match the device_id from request
        $deviceId = request()->header('X-Device-ID') ?: request()->input('device_id');
        return $aiChat->device_id === $deviceId;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(?User $user, AiChat $aiChat): bool
    {
        return $this->view($user, $aiChat);
    }
}
