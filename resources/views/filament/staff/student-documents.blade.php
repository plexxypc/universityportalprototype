@php
    $documents = $this->documentRows();
@endphp

<div>
    @if ($documents === [])
        <p>No documents yet.</p>
    @else
        <ul>
            @foreach ($documents as $document)
                <li>
                    {{ $document['kind'] }} · {{ $document['mime'] }}
                    <a href="{{ $document['url'] }}">Download</a>
                    @if ($document['can_remove'])
                        <button type="button" wire:click="removeDocument({{ $document['id'] }})">Remove</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
