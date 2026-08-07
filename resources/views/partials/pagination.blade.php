{{-- Постраничная навигация в разметке эталона. --}}
@if($paginator->lastPage() > 1)
<nav class="woocommerce-pagination">
	<ul class='page-numbers'>
	@if($paginator->currentPage() > 1)
	<li><a class="prev page-numbers" href="{{ $paginator->previousPageUrl() }}">&larr;</a></li>
	@endif
	@for($page = 1; $page <= $paginator->lastPage(); $page++)
		@if($page === $paginator->currentPage())
	<li><span aria-label="Стр. {{ $page }}" aria-current="page" class="page-numbers current">{{ $page }}</span></li>
		@else
	<li><a aria-label="Стр. {{ $page }}" class="page-numbers" href="{{ $paginator->url($page) }}">{{ $page }}</a></li>
		@endif
	@endfor
	@if($paginator->hasMorePages())
	<li><a class="next page-numbers" href="{{ $paginator->nextPageUrl() }}">&rarr;</a></li>
	@endif
</ul>
</nav>
@endif
