<?php

namespace App\Policies;

use App\Models\Poll;
use App\Models\User;

class PollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Poll $poll): bool
    {
        return $user->isAdmin() && (int) $poll->created_by === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Poll $poll): bool
    {
        if (!$this->view($user, $poll)) {
            return false;
        }

        $hasVotes = $poll->options()->where("votes_count", ">", 0)->exists();

        return !$hasVotes;
    }

    public function delete(User $user, Poll $poll): bool
    {
        return $this->view($user, $poll);
    }

    public function viewResults(User $user, Poll $poll): bool
    {
        return $this->view($user, $poll);
    }
}

