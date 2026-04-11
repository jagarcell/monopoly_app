<?php

namespace App\Enums;

enum CommunityChestCardAction: string
{
    case AdvanceTo              = 'advance_to';
    case Collect                = 'collect';
    case Pay                    = 'pay';
    case GoToJail               = 'go_to_jail';
    case GetOutOfJailFree       = 'get_out_of_jail_free';
    case CollectFromEachPlayer  = 'collect_from_each_player';
    case PropertyRepairs        = 'property_repairs';
}
