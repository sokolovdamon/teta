<?php

use Illuminate\Support\Facades\Broadcast;

// Personal channel: notification centre, support replies, booking updates.
Broadcast::channel('users.{id}', fn ($user, string $id) => $user->id === $id);
