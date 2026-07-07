// import './bootstrap';
import '../css/app.css';
// // import '@flux-ui/core';
import React from 'react'
import ReactDOM from 'react-dom/client'
import StoreList from './pages/StoreList';
// resources/js/app.jsx
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";


function App() {
    return <StoreList />
}

const queryClient = new QueryClient();

ReactDOM.createRoot(document.getElementById("app")).render(
    <QueryClientProvider client={queryClient}>
        <App />
    </QueryClientProvider>
);