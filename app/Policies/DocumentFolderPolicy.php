<?php

namespace App\Policies;

use App\Models\DocumentFolder;
use App\Models\User;

class DocumentFolderPolicy
{
    /**
     * View a folder and download its files. Delegates to the folder's
     * private-by-default visibility rule, resolved against the user's
     * membership for that folder's company.
     */
    public function view(User $user, DocumentFolder $documentFolder): bool
    {
        $membership = $user->companyMembership($documentFolder->company);

        return $membership !== null && $documentFolder->isVisibleTo($membership);
    }

    /**
     * Rename, move, share or delete a folder. Limited to Owner/Admin and the
     * folder's creator.
     */
    public function update(User $user, DocumentFolder $documentFolder): bool
    {
        $membership = $user->companyMembership($documentFolder->company);

        return $membership !== null && $documentFolder->isManageableBy($membership);
    }

    public function delete(User $user, DocumentFolder $documentFolder): bool
    {
        return $this->update($user, $documentFolder);
    }
}
