<fieldset class="grid gap-3 sm:grid-cols-2">
    <legend class="mb-3 text-sm font-semibold">Catégories autorisées</legend>
    @foreach (\App\Services\Crm\PortalCategoryAccess::catalogue() as $key => $category)
        <label class="flex items-center gap-2 text-sm" for="{{ $categoryPrefix }}-{{ $key }}">
            @php($mandatoryReport = ($categoryKind ?? null) === 'delegataire' && $key === 'report')
            <input type="checkbox" id="{{ $categoryPrefix }}-{{ $key }}" name="categories[]" value="{{ $key }}" @checked($mandatoryReport || in_array($key, $selectedCategories, true)) @disabled($mandatoryReport)>
            @if ($mandatoryReport)<input type="hidden" name="categories[]" value="report">@endif
            {{ $category['label'] }}
        </label>
    @endforeach
</fieldset>
