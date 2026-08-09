<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting;

/**
 * Channel types for broadcasting. A channel can be public (anyone can listen),
 * private (requires authentication), or presence (private + member tracking).
 */
final class Channel
{
    private function __construct(
        public readonly string $name,
        public readonly ChannelType $type,
    ) {
    }

    public static function public(string $name): self
    {
        return new self($name, ChannelType::Public);
    }

    public static function private(string $name): self
    {
        return new self('private-' . $name, ChannelType::Private);
    }

    public static function presence(string $name): self
    {
        return new self('presence-' . $name, ChannelType::Presence);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
