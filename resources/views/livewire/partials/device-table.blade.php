<flux:table>
    <flux:table.columns>
        <flux:table.column>{{ $label }}</flux:table.column>
        <flux:table.column align="end">Revenue</flux:table.column>
        <flux:table.column align="end">Impressions</flux:table.column>
        <flux:table.column align="end">Fill</flux:table.column>
        <flux:table.column align="end">eCPM</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($rows as $row)
            <flux:table.row :key="$row->{$key}">
                <flux:table.cell variant="strong">{{ $row->{$key} }}</flux:table.cell>
                <flux:table.cell align="end">€ {{ number_format($row->revenue, 2) }}</flux:table.cell>
                <flux:table.cell align="end">{{ number_format($row->impressions) }}</flux:table.cell>
                <flux:table.cell align="end">{{ $row->fill !== null ? number_format($row->fill * 100, 1).'%' : '—' }}</flux:table.cell>
                <flux:table.cell align="end">{{ $row->ecpm !== null ? '€ '.number_format($row->ecpm, 2) : '—' }}</flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
