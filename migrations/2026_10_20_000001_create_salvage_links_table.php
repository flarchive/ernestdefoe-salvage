<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('salvage_links', function (Blueprint $table) {
            $table->increments('id');
            // Exactly as stored in the post's XML, so rewrites match byte for byte.
            $table->text('url');
            $table->char('url_hash', 40)->unique();
            $table->string('host', 255)->index();
            // link | image
            $table->string('kind', 5);
            // pending | ok | broken | blocked (a bot wall: not counted as broken) | skipped (never fetched)
            $table->string('status', 7)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 20)->nullable();
            $table->unsignedTinyInteger('fail_count')->default(0);
            // mirror | replace | unlink | ignore: what staff (or the mirror) decided.
            $table->string('action', 7)->nullable();
            $table->text('replacement')->nullable();
            // Finds the original again from a post that already carries the replacement.
            $table->char('replacement_hash', 40)->nullable()->index();
            // The replacement before the current decision, while posts still carry it.
            $table->text('prior')->nullable();
            // Posts still to be rewritten for the current action.
            $table->boolean('apply_pending')->default(false)->index();
            $table->unsignedTinyInteger('mirror_tries')->default(0);
            // Our own disk only; null when FoF Upload holds the copy.
            $table->string('mirror_path', 100)->nullable();
            $table->text('archive_url')->nullable();
            $table->dateTime('archive_checked_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            // Null: never checked again (skipped, ignored, unlinked, mirrored).
            $table->dateTime('next_check_at')->nullable()->index();
            $table->dateTime('broken_at')->nullable();
            $table->dateTime('created_at');
        });

        $schema->create('salvage_link_post', function (Blueprint $table) {
            $table->unsignedInteger('link_id');
            $table->unsignedInteger('post_id');
            $table->primary(['link_id', 'post_id']);
            $table->index('post_id');
            $table->foreign('link_id')->references('id')->on('salvage_links')->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('salvage_link_post');
        $schema->dropIfExists('salvage_links');
    },
];
