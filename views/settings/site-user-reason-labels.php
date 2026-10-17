<?php

declare(strict_types=1);

// EN: Translate stable reason codes identically in the picker, preview and durable report.
//
// HR: Stabilne oznake razloga jednako prevodi u izborniku, pregledu i trajnom izvještaju.
$siteUserReasonLabels = [
    'username' => __('Automatski: korisničko ime'),
    'email' => __('Automatski: email'),
    'username_email' => __('Automatski: korisničko ime i email'),
    'auto_create' => __('Automatska izrada neaktivnog računa'),
    'group_name' => __('Automatski: naziv grupe'),
    'group_auto_create' => __('Automatski: izrada nove grupe'),
    'confirmed' => __('Ranije potvrđeno mapiranje'),
    'manual' => __('Ručni odabir'),
    'unmapped' => __('Nemapirano'),
    'account_disabled' => __('Automatski preskočen: izvorni račun je neaktivan'),
    'directory_disabled' => __('Automatski preskočen: izvorni imenik je ugašen'),
    'reference_only' => __('Automatski preskočeno: samo povijesna referenca autora'),
    'conflict' => __('Potrebna ručna provjera'),
];
$siteUserConflictLabels = [
    'ambiguous' => __('Korisničko ime i/ili email upućuju na više ciljnih računa.'),
    'collision' => __('Login ili email ponavlja se u izvoru ili već postoji u Simbiozi.'),
    'invalid_login' => __('Login nije valjan za novi račun. Odaberite postojeći račun.'),
    'invalid_email' => __('Email nije valjan za novi račun. Potrebna je ručna provjera.'),
    'group_ambiguous' => __('Naziv grupe nije jednoznačan ili pripada sistemskoj ili isključenoj grupi.'),
    'group_invalid' => __('Naziv nije valjan za novu grupu. Potrebna je ručna provjera.'),
];
