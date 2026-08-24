<?php

namespace App\Http\Controllers;

use App\Models\ContactLog;
use App\Rules\CleanContentRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MessageController extends Controller
{
    /**
     * Display a listing of messages received by the authenticated student.
     */
    public function index(): Response
    {
        $user = Auth::user();

        $messages = ContactLog::where('student_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $unreadCount = ContactLog::where('student_id', $user->id)
            ->unread()
            ->count();

        return Inertia::render('Messages/Index', [
            'messages' => $messages,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * Mark a message as read.
     */
    public function markAsRead(ContactLog $message): RedirectResponse
    {
        // Authorization: ensure the message belongs to the student
        if ($message->student_id !== Auth::id()) {
            abort(403, 'No autorizado para acceder a este mensaje.');
        }

        if (is_null($message->read_at)) {
            $message->update(['read_at' => now()]);
        }

        return redirect()->back();
    }

    /**
     * Delete a contact message from the student's inbox.
     */
    public function destroy(ContactLog $message): RedirectResponse
    {
        if ($message->student_id !== Auth::id()) {
            abort(403, 'No autorizado para eliminar este mensaje.');
        }

        $message->delete();

        return redirect()->route('messages.index')->with('status', 'Mensaje eliminado correctamente.');
    }
}
