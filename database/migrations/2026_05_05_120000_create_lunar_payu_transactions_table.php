<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->prefix.'payu_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained($this->prefix.'orders')
                ->nullOnDelete();

            // The PayU-side order identifier (returned by `POST /orders`).
            // Indexed for webhook lookups. Nullable until the API call succeeds.
            $table->string('payu_order_id')->nullable()->index();

            // Lifecycle status — see PayuTransactionStatus enum.
            $table->string('status')->default(PayuTransactionStatus::Pending->value)->index();

            // Authorization amount in minor units + currency at the time the
            // transaction was opened.
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PLN');

            // Customer redirect URL returned by PayU.
            $table->text('redirect_url')->nullable();

            // Last raw event from PayU — request error, webhook payload, or
            // anything else useful for forensics. Stored as JSON.
            $table->json('last_event')->nullable();

            $table->timestamp('last_notification_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix.'payu_transactions');
    }
};
