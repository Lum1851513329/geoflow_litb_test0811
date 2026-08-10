<?php

namespace App\Exceptions;

use DomainException;

final class ImmutablePromptVersionException extends DomainException
{
    public static function hasActivePresetDependency(int $versionId): self
    {
        return new self("Prompt module version {$versionId} is referenced by an active preset.");
    }

    public static function cannotPublishArchived(int $versionId): self
    {
        return new self("Archived prompt module version {$versionId} cannot be published.");
    }
}
