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
  // Radix treats `value === ''` as "nothing selected" — `shouldShowPlaceholder` in
  // @radix-ui/react-select — and refuses to portal that item's `SelectItemText` into the
  // closed trigger even though `options` still lists it. Left unset, the trigger's
  // `placeholder` prop defaults to `''`, so a blank value rendered as an empty box with
  // just the caret. The fix is to hand Radix the blank option's own label as that
  // placeholder — every call site that wants a blank state already prepends one
  // (`{ value: '', label: 'Select gender' }`-shaped, see `ProfileForm`'s `withBlank` and
  // the admin employee/office pickers), so this is the one label already on hand, not a
  // new string to keep in sync.
  const blankLabel = options.find((option) => option.value === '')?.label

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
          <RadixSelect.Value placeholder={blankLabel} />
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
