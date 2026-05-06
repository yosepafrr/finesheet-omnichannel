<div class="{{ isset($isMobile) ? 'w-full' : '' }}">
    @if ($editingHppId === ($type . '-' . $id))
        <div class="flex items-center gap-1 animate-fadeIn">
            <div class="relative w-full">
                <span class="absolute left-2 top-1.5 text-xs text-gray-400">Rp</span>
                <input type="number" 
                       wire:model="newHppValue" 
                       class="w-full pl-7 pr-1 py-1 text-xs border border-[#304674] rounded-md focus:ring-1 focus:ring-[#304674] focus:outline-none"
                       placeholder="0">
            </div>
            <button wire:click="updateHpp{{ $type == 'variant' ? 'Variant' : 'Produk' }}({{ $id }})" 
                    class="p-1 bg-[#304674] text-white rounded hover:bg-[#25365a]" title="Save">
                <span class="material-symbols-rounded text-sm">check</span>
            </button>
            <button wire:click="$set('editingHppId', null)" 
                    class="p-1 bg-gray-200 text-gray-600 rounded hover:bg-gray-300" title="Cancel">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @else
        <div class="flex items-center justify-between group/edit {{ isset($isMobile) ? 'w-full gap-2' : 'gap-4' }}">
            <span class="text-xs font-medium text-gray-600 {{ $hpp ? '' : 'text-gray-400 italic' }}">
                {{ $hpp ? 'Rp ' . number_format($hpp, 0, ',', '.') : 'Belum diisi' }}
            </span>
            <button wire:click="showHppInput('{{ $type }}', {{ $id }}, {{ $hpp ?? 0 }})" 
                    class="text-gray-300 hover:text-[#304674] transition-colors {{ isset($isMobile) ? 'bg-gray-100 p-1 rounded' : 'opacity-0 group-hover/edit:opacity-100' }}">
                <span class="material-symbols-rounded text-sm">edit</span>
            </button>
        </div>
    @endif
</div>