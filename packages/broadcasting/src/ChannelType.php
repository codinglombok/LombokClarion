<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting;

enum ChannelType: string
{
    case Public = 'public';
    case Private = 'private';
    case Presence = 'presence';
}
