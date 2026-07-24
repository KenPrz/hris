'use client'

import * as RadixSelect from '@radix-ui/react-select'

export interface SelectOption {
  value: string
  label: string
}

export interface SelectProps {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
}

/**
 * Radix-backed listbox with a real `<label>` — `htmlFor` points at the id Radix puts on
 * its trigger `<button>`, so `getByLabelText` (and a screen reader) reach it exactly like
 * a native `<select>`. Keyboard navigation, typeahead, and the open/close state machine
 * are all Radix's.
 */
export function Select({ id, label, value, onChange, options }: SelectProps) {
  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
      <label
        htmlFor={id}
        style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
      >
        {label}
      </label>
      <RadixSelect.Root value={value} onValueChange={onChange}>
        <RadixSelect.Trigger
          id={id}
          className="inline-flex items-center justify-between focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
          style={{
            background: 'var(--surface-1)',
            color: 'var(--ink)',
            border: 'none',
            borderBottom: '1px solid var(--field-border)',
            borderRadius: 'var(--radius)',
            padding: 'calc(var(--sp-sm) - 1px) var(--sp-md)',
            font: 'var(--t-body)',
            letterSpacing: 'var(--ls-body)',
            gap: 'var(--sp-xs)',
          }}
        >
          <RadixSelect.Value />
          <RadixSelect.Icon aria-hidden="true">▾</RadixSelect.Icon>
        </RadixSelect.Trigger>
        <RadixSelect.Portal>
          <RadixSelect.Content
            position="popper"
            sideOffset={4}
            className="overflow-hidden"
            style={{
              background: 'var(--canvas)',
              color: 'var(--ink)',
              borderRadius: 'var(--radius)',
              border: '1px solid var(--hairline)',
              zIndex: 50,
            }}
          >
            <RadixSelect.Viewport>
              {options.map((option) => (
                <RadixSelect.Item
                  key={option.value}
                  value={option.value}
                  className="outline-none data-[highlighted]:bg-[var(--surface-1)]"
                  style={{
                    padding: 'var(--sp-xs) var(--sp-md)',
                    font: 'var(--t-body)',
                    letterSpacing: 'var(--ls-body)',
                    cursor: 'pointer',
                  }}
                >
                  <RadixSelect.ItemText>{option.label}</RadixSelect.ItemText>
                </RadixSelect.Item>
              ))}
            </RadixSelect.Viewport>
          </RadixSelect.Content>
        </RadixSelect.Portal>
      </RadixSelect.Root>
    </div>
  )
}
