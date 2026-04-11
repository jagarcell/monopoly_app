<?php

namespace App\Enums;

enum ChanceCardAction: string
{
    case AdvanceTo          = 'advance_to';
    case AdvanceToNearest   = 'advance_to_nearest';
    case Collect            = 'collect';
    case Pay                = 'pay';
    case PayEachPlayer      = 'pay_each_player';
    case MoveBack           = 'move_back';
    case GoToJail           = 'go_to_jail';
    case GetOutOfJailFree   = 'get_out_of_jail_free';
    case PropertyRepairs    = 'property_repairs';
}
