import { useEffect, useState } from 'react';
import napi from '../utils/axiosnapi'; // adjust path if needed

export function usePbnEntries(includePosted: boolean) {
  const [items, setItems] = useState([]);
  const [search, setSearch] = useState('');

  useEffect(() => {
    const fetch = async () => {

      const storedUser = localStorage.getItem('user');
      const user = storedUser ? JSON.parse(storedUser) : null;

      const res = await napi.get('/api/pbn/dropdown-list', {
        params: { 
            include_posted: includePosted.toString(), 
            company_id: user?.company_id || '', // ✅ Add company_id
        },
        
      });
      console.log('how');
      console.log(res.data);
      console.log(res);
      setItems(res.data);
    };
    fetch();
  }, [includePosted]);

  const filtered = items.filter(item =>
    Object.values(item).some(val =>
      String(val).toLowerCase().includes(search.toLowerCase())
    )
  );

  return { items: filtered, search, setSearch };
}
