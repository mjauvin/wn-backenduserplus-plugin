<?php Block::put('breadcrumb') ?>
    <ul>
        <li><a href="<?= Backend::url('backend/users') ?>"><?= e(trans('backend::lang.user.menu_label')) ?></a></li>
        <li><?= e(trans($this->pageTitle)) ?></li>
    </ul>
<?php Block::endPut() ?>

<?php if (!$this->fatalError): ?>

    <?php Block::put('form-contents') ?>
        <?php if (!$this->formGetWidget()->getField('_hint_trashed') && $formModel->trashed()): ?>
            <?= $this->makePartial('hint_trashed') ?>
        <?php endif; ?>

        <div class="layout">

            <div class="layout-row">
                <?= $this->formRenderOutsideFields() ?>
                <?= $this->formRenderPrimaryTabs() ?>
            </div>

            <div class="form-buttons">
                <?= $this->makePartial('form_update_toolbar'); ?>
            </div>

        </div>
    <?php Block::endPut() ?>

    <?php Block::put('form-sidebar') ?>
        <div class="hide-tabs"><?= $this->formRenderSecondaryTabs() ?></div>
    <?php Block::endPut() ?>

    <?php Block::put('body') ?>
        <?= Form::open(['class'=>'layout stretch']) ?>
            <?= $this->makeLayout('form-with-sidebar') ?>
        <?= Form::close() ?>
    <?php Block::endPut() ?>

<?php else: ?>
    <div class="control-breadcrumb">
        <?= Block::placeholder('breadcrumb') ?>
    </div>
    <div class="padded-container">
        <p class="flash-message static error"><?= e(trans($this->fatalError)) ?></p>
        <p><a href="<?= Backend::url('backend/users') ?>" class="btn btn-default"><?= e(trans('backend::lang.user.return')) ?></a></p>
    </div>
<?php endif ?>
