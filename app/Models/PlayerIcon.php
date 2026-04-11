<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerIcon extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'player_icons';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'image_url',
        'sort_order',
    ];
}
