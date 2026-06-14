<?php

namespace App\Services\Audio;

class AudioMetadataAiReviewPrompts
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function review(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","reason":"short reason"}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Use accept only when the candidate is very likely the same recording or a clearly better cover for the same album.',
            'Use reject when the only supporting evidence is duration, or when title/artist/album/source hints point to a different work.',
            'Use ambiguous when evidence is plausible but insufficient for a reviewable proposal.',
            'Do not invent new metadata. Judge only the candidate values already supplied.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function anomaly(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","reason":"short reason","source_identity_supported":true,"selected_track_position":"2","selected_track_title":"source track title"}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Use accept only when the fingerprint candidate and source release are likely the same recording/release, and one listed source track plausibly represents the current track under another language, romanization, or import/custom title.',
            'Set source_identity_supported to true only when the supplied release and selected source track are coherent with the current title, artists, album, filename, duration, and fingerprint candidate. Set it to false for merely similar album names, unrelated artists, unrelated selected tracks, or weak search-result matches.',
            'Use reject when the source release or selected track points to a different work.',
            'Use ambiguous when the listed evidence is plausible but insufficient.',
            'Do not invent canonical titles, artists, albums, release details, IDs, or track positions. Select one track from the supplied source.tracklist only.',
            'Canonical/source fields should be original/source values from the selected release or track.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function fieldReview(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this shape: {"verdict":"ambiguous","reason":"one concrete overall judgement","safe_fields":[],"field_reviews":{"album":{"verdict":"ambiguous","reason":"one concrete reason for this field"}}}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'You are judging field-level safety for an Atlas audio metadata proposal.',
            'Use only the supplied JSON evidence. Do not invent metadata and do not repair values.',
            'Never return placeholder reason text such as "short summary", "short reason", or "field-specific reason".',
            'safe_fields must be a subset of candidate.values keys. Never include a field that is absent from candidate.values, even if it is shown in examples or current_values.',
            'field_reviews must include one entry for every candidate.values key you judged, with that field-specific verdict and reason.',
            'safe_fields should contain exactly the field_reviews keys whose verdict is accept.',
            'A strong fingerprint can prove a recording while still failing to prove the correct release, album, edition, disc, cover, label, catalog number, barcode, country, or release date.',
            'Include a field in safe_fields only when that exact field is coherent with the current title, artist, album/group, filename, duration, and provider evidence.',
            'Title is unsafe when the current and proposed titles have different remix, mix, version, update, edit, live, remaster, vinyl, or edition descriptors. Title case, bracket-vs-parenthesis style, apostrophes, punctuation, and whitespace alone are safe when normalized title tokens and mix descriptors match.',
            'Album, cover_url, track_number, disc_number, release_label, catalog_number, barcode, release_date, release_country, musicbrainz_release_id, and discogs_release_id require release-level support, not only recording support.',
            'Compare track title only to candidate title. Compare current album only to candidate album or source release title. Do not compare the track title to the album title.',
            'Use ambiguous with a reduced safe_fields list when the recording likely matches but release-level details may describe a different single, remix package, compilation, edition, or disc.',
            'Use reject with an empty safe_fields list when even recording identity is not coherent.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".'Hard rule: For provider discogs_release with discogs_release_id, track_position, duration_delta_seconds <= 2, and matched_existing_fields containing artists, track, and duration, return accept unless current title, current artists, or current duration contradict candidate.values. Missing or different release-only current fields such as album, cover_url, track_number, release_label, catalog_number, barcode, release_date, release_country, and discogs_release_id are proposed corrections, not conflict evidence. Under this hard Discogs rule, safe_fields must include every one of these candidate.values keys when present: album, cover_url, track_number, release_label, catalog_number, release_date, release_country, discogs_release_id, and field_reviews for those keys must use verdict accept.',
        ]);
    }
}
