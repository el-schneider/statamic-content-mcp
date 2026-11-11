export interface Tab {
    title: string;
    url: string;
    active: boolean;
}

export interface Connection {
    client_id: string;
    user_id: string;
    app: string;
    redirect_host: string | null;
    user: { id: string; name: string | null; email: string } | null;
    first_connected_at: string;
    last_token_issued_at: string;
    state: 'active' | 'idle' | 'expired';
    disconnect_url: string;
}
