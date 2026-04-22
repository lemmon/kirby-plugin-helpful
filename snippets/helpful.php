<?php

declare(strict_types=1);

use Lemmon\Helpful\Helpful;

/**
 * @var \Kirby\Cms\Page|null $page
 * @var string|null $question
 * @var string|null $yesLabel
 * @var string|null $noLabel
 * @var string|null $confirmation
 */

$page ??= page();

if (!$page) {
    return;
}

// Kirby page UUID (`page://…`); stable across folder renames, unlike `$page->id()`.
$pageUri = $page->uuid()->toString();

// i18n via Kirby translations; English defaults when no translation exists.
// Per-call overrides win (passed as snippet parameters).
$question     ??= t('helpful.question',     Helpful::DEFAULT_QUESTION);
$yesLabel     ??= t('helpful.yes',          Helpful::DEFAULT_YES_LABEL);
$noLabel      ??= t('helpful.no',           Helpful::DEFAULT_NO_LABEL);
$confirmation ??= t('helpful.confirmation', Helpful::DEFAULT_CONFIRMATION);

if (Helpful::hasVoted($pageUri)): ?>
    <div class="helpful helpful--confirmed">
        <p class="helpful__question"><?= esc($question) ?></p>
        <p class="helpful__confirmation" role="status" aria-live="polite"><?= esc($confirmation) ?></p>
    </div>
<?php return; endif;

$action = url('helpful');
$token  = Helpful::token($pageUri);

if ($token === '') {
    return;
}
?>
<form class="helpful" method="POST" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="this" hx-swap="outerHTML">
    <input type="hidden" name="page" value="<?= esc($pageUri, 'attr') ?>">
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">

    <p class="helpful__question"><?= esc($question) ?></p>

    <div class="helpful__actions">
        <button type="submit" name="value" value="yes" class="helpful__button"><?= esc($yesLabel) ?></button>
        <button type="submit" name="value" value="no" class="helpful__button"><?= esc($noLabel) ?></button>
    </div>
</form>
