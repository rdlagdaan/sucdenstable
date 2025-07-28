import { useEffect, useState } from 'react';
import axios from '../utils/axiosnapi';

export interface DropdownItem {
  code: string;
  label?: string;
  description?: string;
  [key: string]: any;
}

export function useDropdownOptions(endpoint: string) {
  const [items, setItems] = useState<DropdownItem[]>([]);
  const [search, setSearch] = useState('');

  useEffect(() => {
    const fetchItems = async () => {
      try {
        const response = await axios.get(endpoint);
        let mapped: DropdownItem[] = [];

        
        // Special mapping for vend-list
        if (endpoint === '/api/vendors') {
          mapped = response.data.map((item: any) => ({
            code: item.vend_code,
            description: item.vend_name, // 👈 vendor name here
          }));
        }
        // Special mapping for crop-years
        else if (endpoint === '/api/crop-years') {
          mapped = response.data.map((item: any) => ({
            code: item.crop_year,
            label: item.begin_year,
            description: item.end_year,
          }));
        } else {
          // Default mapping for other endpoints (sugar types, vendors, etc.)
          mapped = response.data.map((item: any) => ({
            code: item.code ?? item.sugar_type ?? item.vend_code ?? item.crop_year,
            //label: item.label, // optional
            description: item.description ?? item.vendor_name ?? item.sugar_type ?? item.crop_year,
          }));
        }

        setItems(mapped);
      } catch (error) {
        console.error(`Error fetching dropdown from ${endpoint}`, error);
      }
    };

    fetchItems();
  }, [endpoint]);

  const filteredItems = items.filter(
    (item) =>
      item.code.toString().toLowerCase().includes(search.toLowerCase()) ||
      item.label?.toString().toLowerCase().includes(search.toLowerCase()) ||
      item.description?.toString().toLowerCase().includes(search.toLowerCase())
  );

  return {
    items: filteredItems,
    setSearch,
    search,
  };


}
