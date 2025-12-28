<?php

declare(strict_types=1);

use Lemmon\Helpful\Helpful;

$page ??= page();

if (!$page || Helpful::isEnabled() === false) {
    return;
}

$pageId = $page->id();
$labels = option('lemmon.helpful.labels', []);
$labels = is_array($labels) ? $labels : [];
$question ??= $labels['question'] ?? Helpful::DEFAULT_QUESTION;
$yesLabel ??= $labels['yes'] ?? Helpful::DEFAULT_YES_LABEL;
$noLabel ??= $labels['no'] ?? Helpful::DEFAULT_NO_LABEL;
$confirmation ??= $labels['confirmation'] ?? Helpful::DEFAULT_CONFIRMATION;

$question = is_string($question) ? $question : Helpful::DEFAULT_QUESTION;
$yesLabel = is_string($yesLabel) ? $yesLabel : Helpful::DEFAULT_YES_LABEL;
$noLabel = is_string($noLabel) ? $noLabel : Helpful::DEFAULT_NO_LABEL;
$confirmation = is_string($confirmation) ? $confirmation : Helpful::DEFAULT_CONFIRMATION;

$allowNoJs = (bool) option('lemmon.helpful.allowNoJs', true);
$htmxEnabled = (bool) option('lemmon.helpful.htmx.enabled', false);

if ($allowNoJs === false && $htmxEnabled === false) {
    return;
}

if (Helpful::hasVoted($pageId)): ?>
    <div class="helpful helpful--confirmed">
        <p class="helpful__question"><?= esc($question) ?></p>
        <p class="helpful__confirmation" role="status" aria-live="polite"><?= esc($confirmation) ?></p>
    </div>
<?php

return;
endif;

$action = url('helpful');
$token = Helpful::token($pageId);

if ($token === '') {
    return;
}

$htmxTarget = option('lemmon.helpful.htmx.target', 'this');
$htmxSwap = option('lemmon.helpful.htmx.swap', 'outerHTML');
$htmxAttributes = '';

if ($htmxEnabled === true) {
    $attributes = [
        'hx-post' => $action,
        'hx-target' => $htmxTarget,
        'hx-swap' => $htmxSwap,
    ];

    foreach ($attributes as $key => $value) {
        if (is_string($value) === false || $value === '') {
            continue;
        }

        $htmxAttributes .= ' ' . $key . '="' . esc($value, 'attr') . '"';
    }
}
?>
<form class="helpful" method="POST" action="<?= esc($action, 'attr') ?>"<?= $htmxAttributes ?>>
    <input type="hidden" name="pageId" value="<?= esc($pageId, 'attr') ?>">
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">

    <p class="helpful__question"><?= esc($question) ?></p>

    <div class="helpful__actions">
        <button type="submit" name="value" value="yes" class="helpful__button"><?= esc($yesLabel) ?></button>
        <button type="submit" name="value" value="no" class="helpful__button"><?= esc($noLabel) ?></button>
    </div>
</form>
