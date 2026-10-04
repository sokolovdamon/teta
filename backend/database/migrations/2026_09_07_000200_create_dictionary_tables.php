<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dictionaries (ADM-13): client requests, approaches, specializations, service types, price categories. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('title');
            // individual | pair
            $table->string('format', 16)->default('individual');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // "Запросы" (DEC-11): 43 requests with landing pages /help/{slug} and /help/para/{slug}.
        Schema::create('client_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_group_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 128);
            $table->string('format', 16)->default('individual');
            $table->string('age_label', 8)->nullable();
            $table->unsignedSmallInteger('carousel_sort')->default(0);
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('landing_lead')->nullable();
            $table->longText('landing_body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            // The same slug may exist for an individual and a pair request: /help/{slug} and /help/para/{slug}.
            $table->unique(['format', 'slug']);
        });

        // "Подходы с пояснениями" (DEC-12).
        Schema::create('approaches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('specializations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Service types: individual 50 min, pair 90 min, supervision, intervision, events.
        Schema::create('service_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->unsignedSmallInteger('duration_min');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // DEC-55: categories by the price of an individual session; bounds editable by admins.
        Schema::create('price_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('title');
            $table->unsignedBigInteger('min_price')->default(0);
            $table->unsignedBigInteger('max_price')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['price_categories', 'service_types', 'specializations', 'approaches', 'client_requests', 'request_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
