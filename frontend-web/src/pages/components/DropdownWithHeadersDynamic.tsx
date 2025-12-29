// src/pages/components/RRDropdownWithHeaders.tsx
import DropdownWithHeaders from './DropdownWithHeaders';

type Props = React.ComponentProps<typeof DropdownWithHeaders>;

export default function RRDropdownWithHeaders(props: Props) {
  // Force “manual widths wins” by using a non-empty label
  // because your DropdownWithHeaders currently auto-computes widths when label === ''
  return (
    <DropdownWithHeaders
      {...props}
      label={props.label && props.label.trim() ? props.label : ' '} // 👈 NOT empty string
      customKey={props.customKey || 'rr'}
    />
  );
}
