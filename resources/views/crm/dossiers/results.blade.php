@include('crm.dossiers.table')
@if ($dossiers->hasPages())
<div class="gc-dossier-pagination">
    {{ $dossiers->links('pagination::tailwind') }}
</div>
@endif
