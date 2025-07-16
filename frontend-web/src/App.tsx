import { BrowserRouter, Routes, Route } from 'react-router-dom';
//import Login from './pages/Login';
import Registration from './pages/Registration';
//import Role from './pages/Role';

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Registration />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
