CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "admin" tinyint(1) not null default '0',
  "is_admin" tinyint(1) not null default '0'
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "files"(
  "id" integer primary key autoincrement not null,
  "source" varchar not null,
  "source_id" varchar,
  "url" varchar,
  "referrer_url" varchar,
  "path" varchar,
  "filename" varchar not null,
  "ext" varchar,
  "size" integer,
  "mime_type" varchar,
  "hash" varchar,
  "title" varchar,
  "description" text,
  "thumbnail_url" varchar,
  "tags" text,
  "parent_id" integer,
  "chapter" varchar,
  "seen_preview_at" datetime,
  "seen_file_at" datetime,
  "is_blacklisted" tinyint(1) not null default '0',
  "blacklist_reason" varchar,
  "liked" tinyint(1) not null default '0',
  "liked_at" datetime,
  "disliked" tinyint(1) not null default '0',
  "disliked_at" datetime,
  "loved" tinyint(1) not null default '0',
  "loved_at" datetime,
  "downloaded" tinyint(1) not null default '0',
  "download_progress" integer not null default '0',
  "downloaded_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "not_found" tinyint(1) not null default '0'
);
CREATE TABLE IF NOT EXISTS "file_metadata"(
  "id" integer primary key autoincrement not null,
  "file_id" integer not null,
  "payload" text,
  "is_review_required" tinyint(1) not null default '0',
  "is_extracted" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("file_id") references "files"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "covers"(
  "id" integer primary key autoincrement not null,
  "path" varchar not null,
  "hash" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "covers_hash_index" on "covers"("hash");
CREATE TABLE IF NOT EXISTS "cover_file"(
  "cover_id" integer not null,
  "file_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("cover_id") references "covers"("id") on delete cascade,
  foreign key("file_id") references "files"("id") on delete cascade,
  primary key("cover_id", "file_id")
);
CREATE TABLE IF NOT EXISTS "artists"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "artist_file"(
  "artist_id" integer not null,
  "file_id" integer not null,
  foreign key("artist_id") references "artists"("id") on delete cascade,
  foreign key("file_id") references "files"("id") on delete cascade,
  primary key("artist_id", "file_id")
);
CREATE TABLE IF NOT EXISTS "albums"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "album_file"(
  "album_id" integer not null,
  "file_id" integer not null,
  foreign key("album_id") references "albums"("id") on delete cascade,
  foreign key("file_id") references "files"("id") on delete cascade,
  primary key("album_id", "file_id")
);
CREATE TABLE IF NOT EXISTS "login_histories"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "ip_address" varchar,
  "user_agent" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2025_06_27_235710_create_files_table',1);
INSERT INTO migrations VALUES(6,'2025_06_28_102140_create_file_metadata_table',2);
INSERT INTO migrations VALUES(7,'2025_06_29_000810_add_not_found_to_files_table',3);
INSERT INTO migrations VALUES(8,'2025_06_29_160843_create_covers_table',4);
INSERT INTO migrations VALUES(9,'2025_06_29_160926_create_cover_file_table',4);
INSERT INTO migrations VALUES(10,'2025_06_30_005858_create_artists_table',5);
INSERT INTO migrations VALUES(11,'2025_06_30_005920_create_artist_file_table',5);
INSERT INTO migrations VALUES(12,'2025_06_30_005943_create_albums_table',5);
INSERT INTO migrations VALUES(13,'2025_06_30_010007_create_album_file_table',5);
INSERT INTO migrations VALUES(14,'2025_07_02_225916_add_is_super_admin_to_users_table',6);
INSERT INTO migrations VALUES(15,'2025_07_02_231153_create_login_histories_table',7);
INSERT INTO migrations VALUES(16,'2025_07_03_010444_rename_is_super_admin_to_admin_in_users_table',8);
INSERT INTO migrations VALUES(17,'2025_07_03_012925_add_is_super_admin_to_users_table',9);
INSERT INTO migrations VALUES(18,'2025_07_03_013613_rename_is_super_admin_to_is_admin_in_users_table',10);
