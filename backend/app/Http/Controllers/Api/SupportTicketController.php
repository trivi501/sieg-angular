<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCommentedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Tickets de soporte con comentarios y notificaciones (base de datos y correo). SIEG:
 * SupportTicketController, respondiendo JSON.
 *
 * Diferencias con SIEG: ver, comentar y cambiar un ticket exige ser su autor, el asignado o Admin (SIEG
 * dejaba abrir cualquiera por su id); asignar es solo de Admin; la notificación de asignación sí se envía
 * (SIEG comparaba contra el valor ya guardado), y cada usuario solo marca como leídas sus notificaciones.
 */
class SupportTicketController extends Controller
{
    private const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];

    private const ESTADOS = ['abierto', 'en_proceso', 'resuelto', 'cerrado'];

    private static function esAdmin(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']);
    }

    private function autorizar(SupportTicket $ticket, User $user): void
    {
        abort_unless(self::esAdmin($user) || $ticket->user_id === $user->id || $ticket->assigned_to === $user->id, 403, 'Este ticket no es tuyo.');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->only(['search', 'status', 'priority']);

        // Admin ve todos; los demás, los suyos y los que tienen asignados.
        $tickets = SupportTicket::with('user:id,name', 'assignedUser:id,name')->withCount('comments')
            ->when(! self::esAdmin($user), fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('assigned_to', $user->id)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->orderBy('created_at', 'desc')
            ->paginate(50)
            ->through(fn (SupportTicket $t) => $this->fila($t));

        return response()->json($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'url' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'priority' => 'required|in:'.implode(',', self::PRIORIDADES),
        ], [], ['title' => 'título', 'description' => 'descripción', 'priority' => 'prioridad', 'image' => 'imagen']);

        $data = [...$validated, 'user_id' => $request->user()->id, 'status' => 'abierto'];

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('tickets', 'public');
        }

        $ticket = SupportTicket::create($data);

        foreach (User::role(['Super Admin', 'Admin'])->get() as $admin) {
            $admin->notify(new TicketCreatedNotification($ticket));
        }

        return response()->json(['message' => 'Ticket creado.', 'id' => $ticket->id], 201);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();
        $this->autorizar($supportTicket, $user);
        $supportTicket->load('user:id,name', 'assignedUser:id,name', 'comments.user:id,name');
        $admin = self::esAdmin($user);

        return response()->json([
            'ticket' => [
                ...$this->fila($supportTicket),
                'description' => $supportTicket->description,
                'url' => $supportTicket->url,
                'comments' => $supportTicket->comments->sortBy('id')->values()->map(fn (TicketComment $c) => [
                    'id' => $c->id, 'user' => $c->user?->name, 'comment' => $c->comment, 'created_at' => $c->created_at?->toIso8601String(),
                ]),
            ],
            // Asignar es de Admin; cambiar el estado, de Admin o del asignado.
            'users' => $admin ? User::orderBy('name')->get(['id', 'name']) : [],
            'canAssign' => $admin,
            'canChangeStatus' => $user->can('tickets-update') && ($admin || $supportTicket->assigned_to === $user->id),
        ]);
    }

    public function update(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();
        $this->autorizar($supportTicket, $user);
        $admin = self::esAdmin($user);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'url' => 'nullable|string|max:500',
            'priority' => 'sometimes|in:'.implode(',', self::PRIORIDADES),
            'status' => 'sometimes|in:'.implode(',', self::ESTADOS),
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        abort_if(array_key_exists('assigned_to', $validated) && ! $admin, 403, 'Solo Admin asigna tickets.');
        abort_if(isset($validated['status']) && ! $admin && $supportTicket->assigned_to !== $user->id, 403, 'Solo Admin o quien lo tiene asignado cambia el estado.');

        $estadoAnterior = $supportTicket->status;
        $asignadoAnterior = $supportTicket->assigned_to;

        $supportTicket->update($validated);

        if (! empty($validated['assigned_to']) && (int) $validated['assigned_to'] !== (int) $asignadoAnterior) {
            User::find($validated['assigned_to'])?->notify(new TicketAssignedNotification($supportTicket));
        }

        if (isset($validated['status']) && in_array($validated['status'], ['resuelto', 'cerrado'], true) && $estadoAnterior !== $validated['status']) {
            $supportTicket->user?->notify(new TicketResolvedNotification($supportTicket));
        }

        return response()->json(['message' => 'Ticket actualizado.']);
    }

    public function comment(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();
        $this->autorizar($supportTicket, $user);

        $validated = $request->validate(['comment' => 'required|string'], ['comment.required' => 'Escribe el comentario.']);

        TicketComment::create(['ticket_id' => $supportTicket->id, 'user_id' => $user->id, 'comment' => $validated['comment']]);

        // Se avisa al autor y al asignado (menos a quien comenta).
        $notify = collect([$supportTicket->user, $supportTicket->assignedUser])
            ->filter(fn ($u) => $u && $u->id !== $user->id)->unique('id');

        foreach ($notify as $destinatario) {
            $destinatario->notify(new TicketCommentedNotification($supportTicket, $validated['comment'], $user->name));
        }

        return response()->json(['message' => 'Comentario agregado.'], 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        $unread = $request->user()->unreadNotifications;

        return response()->json([
            'unread' => $unread->map(fn ($n) => [
                'id' => $n->id,
                'message' => $n->data['message'] ?? '',
                'ticket_id' => $n->data['ticket_id'] ?? null,
                'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
            'count' => $unread->count(),
        ]);
    }

    /** Solo las notificaciones propias. */
    public function markNotification(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['success' => true]);
    }

    private function fila(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'priority' => $t->priority,
            'status' => $t->status,
            'user' => $t->user?->name,
            'user_id' => $t->user_id,
            'assigned_to' => $t->assigned_to,
            'assigned' => $t->assignedUser?->name,
            'image' => $t->image ? Storage::disk('public')->url($t->image) : null,
            'comments_count' => $t->comments_count ?? $t->comments?->count(),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
