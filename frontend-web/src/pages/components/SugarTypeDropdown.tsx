import { Listbox } from '@headlessui/react';

const sugarTypes = [
  { code: 'A', description: 'A-Quedans' },
  { code: 'B', description: 'B-Quedans' },
  { code: 'C', description: 'C-Quedans' },
  { code: 'D', description: 'D-Quedans' },
];

type Props = {
  value: string;
  onChange: (value: string) => void;
};

export default function SugarTypeDropdown({ value, onChange }: Props) {
  const selectedItem = sugarTypes.find(s => s.code === value) || sugarTypes[0];

  return (
    <div className="w-72">
      <label className="block mb-1 text-sm font-medium">Sugar Type</label>
      <Listbox value={value} onChange={onChange}>
        <div className="relative">
          <Listbox.Button className="w-full rounded border p-2 text-left bg-white">
            {selectedItem.code} - {selectedItem.description}
          </Listbox.Button>
          <Listbox.Options className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded border bg-white shadow">
            {/* Column headers */}
            <div className="grid grid-cols-2 text-xs font-semibold px-2 py-1 border-b bg-gray-100">
              <div>Sugar Type</div>
              <div>Description</div>
            </div>

            {sugarTypes.map((item) => (
              <Listbox.Option
                key={item.code}
                value={item.code}
                className={({ active }) =>
                  `grid grid-cols-2 px-2 py-1 cursor-pointer ${
                    active ? 'bg-blue-500 text-white' : 'text-gray-900'
                  }`
                }
              >
                <>
                  <div>{item.code}</div>
                  <div>{item.description}</div>
                </>
              </Listbox.Option>
            ))}
          </Listbox.Options>
        </div>
      </Listbox>
    </div>
  );
}
