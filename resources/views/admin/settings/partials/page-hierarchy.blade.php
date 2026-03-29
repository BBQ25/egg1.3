@php
    $path = $path ?? [];
@endphp

@foreach($node as $key => $child)
    @if($key === '__leaves')
        @foreach($child as $leaf)
            @php
                $pageLabel = (string) $leaf['label'];
                $pageValue = (string) $leaf['value'];
                $searchText = \Illuminate\Support\Str::lower($pageLabel . ' ' . $pageValue);
            @endphp
            <div class="disabled-page-leaf mb-3" data-page-leaf data-page-value="{{ $pageValue }}" data-page-label="{{ $pageLabel }}" data-search-text="{{ $searchText }}">
                <div class="border rounded-3 px-3 py-3 bg-lighter">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <input name="disabled_pages[]" class="d-none visibility-toggle" type="checkbox" value="{{ $pageValue }}"
                                id="disabled_page_{{ $pageValue }}" @checked(in_array($pageValue, $checkedPages)) data-bulk-leaf data-page-input />
                            <label class="w-100" for="disabled_page_{{ $pageValue }}" style="cursor: pointer;">
                                <span class="d-flex align-items-center text-heading fw-medium mb-1">
                                    <i class="bx bx-show text-success me-2 fs-5 visibility-icon-on"></i>
                                    <i class="bx bx-hide text-danger me-2 fs-5 visibility-icon-off d-none"></i>
                                    <span class="text-truncate">{{ $pageLabel }}</span>
                                </span>
                                <span class="text-muted d-flex align-items-center flex-wrap gap-2 small ms-4 ps-1">
                                    <span>{{ $pageValue }}</span>
                                    <span class="badge bg-label-secondary">Page</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @else
        @php
            $folderSlug = \Illuminate\Support\Str::slug((string) $key);
            $folderPath = array_merge($path, [$folderSlug]);
            $folderId = 'disabled_folder_' . implode('_', $folderPath);

            $folderPages = (isset($collectLeafValues) && is_callable($collectLeafValues)) ? $collectLeafValues($child) : [];
            $folderTotal = count($folderPages);
            $folderChecked = count(array_intersect($folderPages, $checkedPages));
            $isFolderFullyHidden = $folderTotal > 0 && $folderChecked === $folderTotal;
        @endphp

        <div class="disabled-page-folder mb-4" data-bulk-node data-folder-node data-folder-title="{{ \Illuminate\Support\Str::lower((string) $key) }}">
            <div class="border rounded-3 p-3 bg-lighter">
                <div class="d-flex align-items-start justify-content-between gap-3" data-bulk-header>
                    <div class="flex-grow-1 min-w-0">
                        <input type="checkbox" class="d-none visibility-toggle" id="{{ $folderId }}" data-bulk-folder
                            @checked($isFolderFullyHidden) />
                        <label class="w-100" for="{{ $folderId }}" style="cursor: pointer;">
                            <span class="d-flex align-items-center fw-semibold text-primary mb-1">
                                <i class="bx bxs-folder text-warning me-2 fs-5"></i>
                                <i class="bx bx-show text-success me-2 fs-5 visibility-icon-on"></i>
                                <i class="bx bx-hide text-danger me-2 fs-5 visibility-icon-off d-none"></i>
                                <span class="text-truncate">{{ $key }}</span>
                            </span>
                            <span class="text-muted d-flex align-items-center flex-wrap gap-2 small ms-4 ps-3">
                                <span><span data-folder-hidden-count>{{ $folderChecked }}</span> / <span data-folder-total-count>{{ $folderTotal }}</span> pages hidden</span>
                                <span class="badge bg-label-warning">Group</span>
                            </span>
                        </label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary disabled-page-collapse-btn" data-folder-collapse aria-expanded="true">
                        <i class="bx bx-chevron-up" data-folder-collapse-icon></i>
                        <span class="ms-1" data-folder-collapse-label>Collapse</span>
                    </button>
                </div>
                <div class="ms-3 ps-4 mt-3" data-bulk-children>
                    @include('admin.settings.partials.page-hierarchy', [
                        'node' => $child,
                        'checkedPages' => $checkedPages,
                        'path' => $folderPath,
                        'collectLeafValues' => $collectLeafValues ?? null,
                    ])
                </div>
            </div>
        </div>
    @endif
@endforeach
