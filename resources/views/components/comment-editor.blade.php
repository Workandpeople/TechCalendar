@props(['id', 'name' => 'body', 'value' => '', 'rows' => 4, 'maxlength' => 10000])
<div class="gc-comment-editor" data-comment-editor>
    <div class="gc-comment-toolbar" data-comment-toolbar role="group" aria-label="Mise en forme du commentaire" hidden>
        <select class="ql-font" aria-label="Police" title="Police">
            <option value="">Police du site</option><option value="serif">Serif</option><option value="monospace">Monospace</option>
        </select>
        <select class="ql-size" aria-label="Taille du texte" title="Taille du texte">
            <option value="12px">Petit</option><option value="" selected>Normal</option><option value="18px">Grand</option><option value="22px">Très grand</option>
        </select>
        <span class="gc-comment-tools">
            <button type="button" class="ql-bold" title="Gras (Ctrl/Cmd+B)" aria-label="Gras"><strong>G</strong></button>
            <button type="button" class="ql-italic" title="Italique (Ctrl/Cmd+I)" aria-label="Italique"><em>I</em></button>
            <button type="button" class="ql-underline" title="Souligner (Ctrl/Cmd+U)" aria-label="Souligner"><u>S</u></button>
            <button type="button" class="ql-strike" title="Barrer" aria-label="Barrer"><s>S</s></button>
        </span>
        <select class="ql-color" aria-label="Couleur du texte" title="Couleur du texte">
            <option value="">Couleur du site</option>
            @foreach (array_combine(\App\Support\CommentContent::formats()['colors'], ['Ardoise', 'Rouge', 'Orange', 'Ocre', 'Vert', 'Bleu canard', 'Bleu', 'Violet']) as $color => $label)
                <option value="{{ $color }}" style="color:{{ $color }}">{{ $label }}</option>
            @endforeach
        </select>
        <span class="gc-comment-tools">
            <button type="button" class="ql-list" value="bullet" title="Liste à puces" aria-label="Liste à puces">&bull;</button>
            <button type="button" class="ql-list" value="ordered" title="Liste numérotée" aria-label="Liste numérotée">1.</button>
            <button type="button" class="ql-clean" title="Effacer la mise en forme" aria-label="Effacer la mise en forme">Tx</button>
            <button type="button" data-comment-undo title="Annuler la saisie" aria-label="Annuler la saisie">&#8630;</button>
            <button type="button" data-comment-redo title="Rétablir la saisie" aria-label="Rétablir la saisie">&#8631;</button>
        </span>
    </div>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" maxlength="{{ $maxlength }}" {{ $attributes->class(['gc-input']) }} data-comment-input>{{ $value }}</textarea>
    <div data-comment-surface hidden></div>
    <p class="gc-comment-editor-error" data-comment-error role="alert" hidden></p>
</div>
