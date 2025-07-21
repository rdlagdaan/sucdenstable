import { getCsrfToken } from '../utils/csrf';
import { useState } from 'react';
import napi from '../utils/axiosnapi';
import Cookies from 'js-cookie';

interface RoleResponse {
  status: string;
  message: string;
  data: {
    id: number;
    role: string;
    created_at: string;
    updated_at: string;
  };
}

const Role = () => {

  const [formData, setFormData] = useState({
    role: '',

  });

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      // Step 1: Get CSRF cookie
      await getCsrfToken(); // centralized

      // Step 2: Submit
      const response = await napi.post<RoleResponse>('/api/roles', formData, {
        headers: {
          'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '',
        },
        withCredentials: true,
      });
      alert(response.data.message);
    } catch (error: any) {
      if (error.response && error.response.status === 409) {
        alert(error.response.data.message);
      } else if (error.response && error.response.data?.message) {
        alert('Error: ' + error.response.data.message);
      } else {
        alert('An unexpected error occurred.');
      }
    }
  };


  return (
    <div
      className="bg-fixed w-screen h-screen bg-cover bg-center flex items-center justify-center"
      style={{ backgroundImage: "url('/accountingSystemBG.jpg')" }}
    >
      <div className="absolute top-[2%] bg-blue backdrop-blur-md shadow-lg rounded-lg p-8 w-full max-w-xl mx-2">
        <div className="flex justify-center items-center gap-4 mb-6">

        </div>
        <h4 className="text-lg font-bold text-center text-gray-800 mb-6">
          Input Role
        </h4>
        <form className="space-y-2" onSubmit={handleSubmit}>





        <div className="grid grid-cols-1 gap-2">


          <div>
            <input
              type="text"
              name="role"
              placeholder="Role"
              onChange={handleChange}
              className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
          </div>
        </div>


        


          <button
            type="submit"
            className="text-lg w-full mt-2 rounded-md bg-indigo-600 px-3 py-2 text-white font-semibold hover:bg-indigo-500"
          >
            Submit
          </button>
        </form>
      </div>
    </div>
  );
};

export default Role;
