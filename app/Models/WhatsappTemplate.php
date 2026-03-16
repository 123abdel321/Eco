<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    use HasFactory;

    protected $connection = 'clientes';

    protected $table = "whatsapp_templates";

    protected $fillable = [
        'nombre',
        'content_sid',
        'variables',
        'media_variable',
        'descripcion',
        'activo'
    ];

    protected $casts = [
        'variables' => 'array',
        'activo' => 'boolean'
    ];
}
