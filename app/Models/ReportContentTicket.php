<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ReportContentTicket extends Model
{
    use HasFactory;
    
    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'ticket_number',
        'user_reporter_id',
        'ad_id',
        'message',
        'status'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'report_content_tickets.ticket_number',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'report_content_tickets.id',
        'report_content_tickets.ticket_number',
        'report_content_tickets.user_reporter_id',
        'report_content_tickets.ad_id',
        'report_content_tickets.message',
        'report_content_tickets.status',
    ];

    /**
     * * Relation to `User` model
     */
    public function user_reporter() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_reporter_id', 'id');
    }

    /**
     * * Relation to `Ad` model
     */
    public function ad() : BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id', 'id');
    }

    /**
     * * Generate Ticket Number
     */
    public function generateTicketNumber()
    {
        $zeroPadding = "0000000";
        $prefixCode = "RCT-";
        $code = "$prefixCode";

        $increment = 0;
        $similiarCode = DB::table('report_content_tickets')->select('ticket_number')
            ->orderBy('ticket_number', 'desc')
            ->first();

        if (!$similiarCode) {
            $increment = 1;
        } else {
            $increment = (int) substr($similiarCode->ticket_number, strlen($code));
            $increment = $increment + 1;
        }

        $code = $code . substr($zeroPadding, strlen("$increment")) . $increment;

        return $code;
    }
}
