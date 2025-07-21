// src/utils/csrf.ts
//import axios from 'axios';
import napi from '../utils/axiosnapi';

export const getCsrfToken = async () => {
  await napi.get('/sanctum/csrf-cookie', {
    withCredentials: true,
  });
};
